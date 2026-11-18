<?php

namespace App\Services\Training;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\RequestStep;
use App\Models\TdApproval;
use App\Models\TdCampaign;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdHistory;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdPlan;
use App\Models\TdPlanItem;
use App\Models\TdProgram;
use App\Models\TdRoom;
use App\Models\TdSession;
use App\Models\User;
use App\Notifications\TrainingMail;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الدفعة 27 — إكمال موديول التدريب: القاعات وتعارض الحجز (قاعة/مدرب)، مقارنة العروض بمعايير موزونة واعتماد الاختيار (BR-05)،
 * طلب تعديل الخطة المعتمدة عبر مسار اعتماد (BR-18)، تأجيل التنفيذ وإعادة فتحه واستبعاد متدرب، السجل السابق للنظام،
 * المرشحون من الاحتياجات المرتبطة، روابط موافقة المدير وتوقيع التعهد برمز بالبريد، رابط تقويم للاشتراك، والمتابعة الدورية للحملات.
 */
class TdExtras
{
    public function __construct(private Workflow $wf) {}

    private static function min(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    public static function deliveryKeys(): array { return array_column(Td::cfg()['deliveries'], 'key') ?: Td::DELIVERY; }
    public static function audienceKeys(): array { return array_column(Td::cfg()['audiences'], 'key') ?: ['staff', 'departments', 'managers']; }

    // ———————————————— القاعات والتعارض ————————————————

    public function saveRoom(array $v, User $u, ?TdRoom $r = null): TdRoom
    {
        if (! Td::manages($u)) abort(403);
        $data = ['name' => trim($v['name']), 'name_en' => $v['name_en'] ?? null, 'location_id' => $v['location_id'] ?? null, 'capacity' => (int) ($v['capacity'] ?? 20),
            'equipment' => array_values(array_filter(array_map('trim', $v['equipment'] ?? []))), 'active' => (bool) ($v['active'] ?? true)];
        if ($r) { $r->update($data); return $r; }
        return TdRoom::create($data);
    }

    private static function overlaps(array $a, array $b): bool
    {
        if ($a['date'] !== $b['date']) return false;
        $af = $a['from'] ?? '00:00'; $at = $a['to'] ?? '23:59'; $bf = $b['from'] ?? '00:00'; $bt = $b['to'] ?? '23:59';
        return $af < $bt && $bf < $at;
    }

    /** FR-TD-075/073: تعارض القاعة أو المدرب مع تنفيذ آخر في نفس الوقت، وسعة القاعة */
    public static function clashes(TdSession $s): array
    {
        $w = [];
        $others = TdSession::whereKeyNot($s->id ?? 0)->whereNotIn('status', ['cancelled', 'closed', 'postponed'])
            ->where('starts_on', '<=', $s->ends_on)->where('ends_on', '>=', $s->starts_on)
            ->where(fn ($q) => $q->when($s->room_id, fn ($x) => $x->orWhere('room_id', $s->room_id))->when($s->trainer_id, fn ($x) => $x->orWhere('trainer_id', $s->trainer_id)))->get();
        if (! $s->room_id && ! $s->trainer_id) $others = collect();
        foreach ($others as $o) foreach ($s->meetings ?? [] as $m) foreach ($o->meetings ?? [] as $om) if (self::overlaps($m, $om)) {
            if ($s->room_id && $o->room_id === $s->room_id) $w['room_clash'] = ['key' => 'room_clash', 'ar' => "القاعة محجوزة لـ «{$o->title}» يوم {$m['date']}", 'en' => "The room is booked for “{$o->title}” on {$m['date']}", 'session_id' => $o->id];
            if ($s->trainer_id && $o->trainer_id === $s->trainer_id) $w['trainer_clash'] = ['key' => 'trainer_clash', 'ar' => "المدرب مرتبط بـ «{$o->title}» يوم {$m['date']}", 'en' => "The trainer is booked for “{$o->title}” on {$m['date']}", 'session_id' => $o->id];
        }
        if ($s->room_id && ($r = TdRoom::find($s->room_id)) && $r->capacity < $s->capacity)
            $w['room_small'] = ['key' => 'room_small', 'ar' => "سعة القاعة {$r->capacity} أقل من سعة التنفيذ", 'en' => "Room capacity {$r->capacity} is below the run's capacity"];
        return array_values($w);
    }

    // ———————————————— مقارنة العروض واعتماد الاختيار (BR-05 / F-14) ————————————————

    /** ترتيب العروض بمعايير موزونة: السعر نسبي (الأرخص 5)، والبقية تقدير 1..5 */
    public static function quoteRank(TdSession $s): array
    {
        $crit = Td::cfg()['quote_criteria']; $quotes = array_values($s->quotes ?? []);
        $prices = array_filter(array_map(fn ($q) => (float) ($q['price'] ?? 0), $quotes));
        $min = $prices ? min($prices) : 0; $sumW = max(1, array_sum(array_column($crit, 'weight')));
        $out = [];
        foreach ($quotes as $i => $q) {
            $total = 0.0; $parts = [];
            foreach ($crit as $c) {
                $val = $c['key'] === 'price' ? (((float) ($q['price'] ?? 0)) > 0 && $min > 0 ? 5 * $min / (float) $q['price'] : 0) : max(0, min(5, (float) ($q['scores'][$c['key']] ?? 0)));
                $parts[$c['key']] = round($val, 2); $total += $val * (float) $c['weight'];
            }
            $out[] = ['index' => $i, 'center' => $q['center'] ?? '', 'price' => $q['price'] ?? null, 'parts' => $parts, 'score' => round($total * 20 / $sumW, 1)];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public function submitQuote(TdSession $s, int $choice, ?string $reason, User $u): TdApproval
    {
        if (! Td::manages($u)) abort(403);
        $quotes = $s->quotes ?? [];
        if (! isset($quotes[$choice])) throw ApiException::field('choice', L::t('اختر عرضاً.', 'Choose a quote.'), 'required');
        $min = (int) Td::cfg()['min_quotes'];
        $rank = self::quoteRank($s);
        $best = $rank[0]['index'] ?? $choice;
        if ((count($quotes) < $min || $best !== $choice) && mb_strlen(trim((string) $reason)) < self::min())
            throw ApiException::field('reason', L::t(count($quotes) < $min ? "العروض أقل من {$min} — اكتب المبرر." : 'العرض المختار ليس الأعلى تقييماً — اكتب المبرر.', count($quotes) < $min ? "Fewer than {$min} quotes — write the justification." : 'The chosen quote is not the top-rated one — write the justification.'), 'reason_required');
        if (TdApproval::where('session_id', $s->id)->where('kind', 'quote')->whereNotIn('status', ['approved', 'rejected', 'cancelled'])->exists())
            throw new ApiException(L::t('يوجد طلب اعتماد قائم لهذا التنفيذ.', 'An approval request is already open for this run.'), 'pending', 409);
        $a = TdApproval::create(['kind' => 'quote', 'session_id' => $s->id, 'data' => ['choice' => $choice, 'quote' => $quotes[$choice], 'rank' => $rank], 'reason' => trim((string) $reason) ?: '—',
            'cost_delta' => (float) ($quotes[$choice]['price'] ?? 0), 'status' => 'pending_hr', 'requested_by' => $u->id]);
        $s->update(['quote_choice' => $choice, 'quote_reason' => $reason, 'quote_status' => 'pending']);
        $this->wf->start($a, null, Workflow::chainFor('quote_choice', ['amount' => (float) ($quotes[$choice]['price'] ?? 0), 'currency' => $s->currency, 'branch' => $s->location_id]), $u);
        $a->refresh();
        if ($a->status === 'approved') $this->applied($a);
        return $a;
    }

    /** BR-18: تعديل بند في خطة معتمدة ← طلب بمبرره وأثره على الموازنة، ولا يُطبَّق قبل الاعتماد */
    public function requestChange(TdPlan $p, ?TdPlanItem $i, array $data, string $reason, float $delta, User $u): TdApproval
    {
        if ($i && TdApproval::where('item_id', $i->id)->where('kind', 'plan_change')->whereNotIn('status', ['approved', 'rejected', 'cancelled'])->exists())
            throw new ApiException(L::t('يوجد طلب تعديل قائم لهذا البند.', 'A change request is already open for this item.'), 'pending', 409);
        $a = TdApproval::create(['kind' => 'plan_change', 'plan_id' => $p->id, 'item_id' => $i?->id, 'data' => $data, 'reason' => $reason, 'cost_delta' => $delta, 'status' => 'pending_hr', 'requested_by' => $u->id]);
        $i?->update(['pending_change' => $data + ['approval_id' => $a->id, 'reason' => $reason]]);
        $this->wf->start($a, null, Workflow::chainFor('td_plan_change', ['amount' => abs($delta), 'currency' => $p->currency, 'branch' => $p->location_id]), $u);
        $a->refresh();
        if ($a->status === 'approved') $this->applied($a);
        return $a;
    }

    public function decide(TdApproval $a, User $u, bool $ok, ?string $note): TdApproval
    {
        $a = $this->wf->decide($a, $u, $ok, $note, false, fn (TdApproval $x) => $this->applied($x));
        if (! $ok) {
            if ($a->kind === 'quote') TdSession::whereKey($a->session_id)->update(['quote_status' => 'rejected']);
            else TdPlanItem::whereKey($a->item_id)->update(['pending_change' => null]);
            if ($a->requested_by) Notify::user($a->requested_by, 'رُفض '.$a->workflowLabel('ar').': '.$note, $a->workflowLabel('en').' declined: '.$note, $a->kind === 'quote' ? 'training:sessions' : 'training:plan');
        }
        return $a->fresh();
    }

    private function applied(TdApproval $a): void
    {
        if ($a->kind === 'quote') {
            $s = TdSession::find($a->session_id); $q = $a->data['quote'] ?? [];
            $costs = $s->costs ?? [];
            if (! collect($costs)->firstWhere('type', 'center') && ! empty($q['price'])) $costs[] = ['type' => 'center', 'amount' => (float) $q['price'], 'currency' => $q['currency'] ?? $s->currency, 'note' => $q['center'] ?? null];
            $s->update(['quote_status' => 'approved', 'quotes_approved_at' => now(), 'quotes_approved_by' => RequestStep::where('subject_type', $a->getMorphClass())->where('subject_id', $a->id)->where('status', 'approved')->orderByDesc('level')->value('acted_by'), 'costs' => $costs]);
            Audit::record('td_quote.approved', $s, null, ['choice' => $a->data['choice'] ?? null]);
            return;
        }
        $p = TdPlan::find($a->plan_id);
        $req = User::find($a->requested_by);
        $data = $a->data; unset($data['approval_id'], $data['reason']);
        $i = $a->item_id ? TdPlanItem::find($a->item_id) : null;
        $ch = $i?->changes ?? [];
        foreach ($ch as &$c) if (($c['approval_id'] ?? null) === $a->id) $c['status'] = 'approved';
        unset($c);
        if ($i) $i->update($data + ['pending_change' => null, 'changes' => $ch]);
        else {
            $i = TdPlanItem::create($data + ['plan_id' => $p->id, 'status' => 'planned', 'changes' => [['at' => now()->toIso8601String(), 'by' => $req?->displayName('ar'), 'what' => 'add', 'reason' => $a->reason, 'cost_delta' => $a->cost_delta, 'status' => 'approved', 'approval_id' => $a->id]]]);
            $a->update(['item_id' => $i->id]);
        }
        Audit::record('td_plan.changed', $p, null, ['item' => $i->id, 'delta' => $a->cost_delta]);
    }

    public static function approvalRow(TdApproval $a, User $viewer): array
    {
        $cur = Workflow::current($a);
        $s = $a->session_id ? TdSession::find($a->session_id) : null; $p = $a->plan_id ? TdPlan::find($a->plan_id) : null;
        return ['kind' => 'td_approval', 'id' => $a->id, 'type' => $a->kind === 'quote' ? 'quote_choice' : 'td_plan_change',
            'type_name' => ['ar' => $a->workflowLabel('ar'), 'en' => $a->workflowLabel('en')], 'employee' => null,
            'from' => $s?->starts_on?->toDateString() ?? ($p ? $p->year.'-01-01' : null), 'to' => $s?->ends_on?->toDateString() ?? ($p ? $p->year.'-12-31' : null), 'status' => $a->status, 'reason' => $a->reason,
            'td_approval' => ['kind' => $a->kind, 'title' => $s?->title ?? ($p ? $p->title.' — '.($a->data['title'] ?? '') : ''), 'data' => $a->data, 'cost_delta' => $a->cost_delta, 'currency' => $s?->currency ?? $p?->currency,
                'session_id' => $a->session_id, 'plan_id' => $a->plan_id],
            'warnings' => [], 'requested_by' => null, 'created_at' => $a->created_at?->toIso8601String(), 'steps' => Workflow::steps($a), 'step_id' => $cur?->id,
            'can_act' => $cur ? Workflow::canAct($viewer, $cur, $a) : false, 'can_cancel' => false];
    }

    /** بوابة التأكيد: عروض مُدخلة دون اعتماد الاختيار */
    public static function quoteGate(TdSession $s): void
    {
        if (! Td::cfg()['quote_gate'] || count($s->quotes ?? []) === 0 || $s->quote_status === 'approved') return;
        throw new ApiException(L::t('اعتمد اختيار العرض الخارجي أولاً (مقارنة العروض).', 'Approve the external quote choice first (quote comparison).'), 'quote_not_approved', 409);
    }

    // ———————————————— التأجيل وإعادة الفتح والاستبعاد ————————————————

    public function postpone(TdSession $s, ?array $meetings, string $reason, User $u): TdSession
    {
        if (! Td::manages($u)) abort(403);
        if (in_array($s->status, ['completed', 'closed', 'cancelled', 'evaluated'], true)) throw new ApiException(L::t('لا يُؤجَّل تنفيذ منتهٍ.', 'A finished run cannot be postponed.'), 'invalid', 409);
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('اكتب سبب التأجيل.', 'Write the reason.'), 'reason_required');
        $ens = TdEnrollment::where('session_id', $s->id)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed', 'waitlist'])->get();
        $sessions = app(Sessions::class);
        foreach ($ens->where('status', 'confirmed') as $en) $sessions->markDays($en, false);
        if ($meetings) {
            usort($meetings, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $s->update(['meetings' => $meetings, 'starts_on' => $meetings[0]['date'], 'ends_on' => end($meetings)['date'], 'postpone_reason' => $reason]);
            if ($s->status === 'postponed') $s->update(['status' => $ens->isNotEmpty() ? 'enrolling' : 'planned']);
            foreach ($ens->where('status', 'confirmed') as $en) $sessions->markDays($en, true);
        } else $s->update(['status' => 'postponed', 'postpone_reason' => $reason]);
        foreach ($ens as $en) if ($uid = User::where('employee_id', $en->employee_id)->value('id'))
            Notify::user($uid, "تأجيل دورة «{$s->title}»".($meetings ? ' إلى '.$s->starts_on->toDateString() : '')." — {$reason}", "“{$s->title}” postponed".($meetings ? ' to '.$s->starts_on->toDateString() : '')." — {$reason}", 'training');
        Audit::record('td_session.postponed', $s, null, ['reason' => $reason, 'to' => $s->starts_on?->toDateString()]);
        return $s->fresh();
    }

    public function reopen(TdSession $s, string $reason, User $u): TdSession
    {
        if (! Td::manages($u)) abort(403);
        if (! in_array($s->status, ['closed', 'evaluated'], true)) throw new ApiException(L::t('يُعاد فتح التنفيذ المغلق فقط.', 'Only a closed run can be reopened.'), 'invalid', 409);
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('اكتب سبب إعادة الفتح.', 'Write the reason.'), 'reason_required');
        $s->update(['status' => 'completed', 'closed_at' => null, 'evaluated_at' => null]);
        Audit::record('td_session.reopened', $s, null, ['reason' => $reason]);
        return $s->fresh();
    }

    public function exclude(TdEnrollment $en, string $reason, User $u): TdEnrollment
    {
        if (! Td::manages($u)) abort(403);
        if (! in_array($en->status, ['nominated', 'pending_commitment', 'confirmed', 'waitlist'], true)) throw new ApiException(L::t('لا يمكن الاستبعاد الآن.', 'Cannot exclude now.'), 'invalid', 409);
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('اكتب سبب الاستبعاد.', 'Write the reason.'), 'reason_required');
        app(Sessions::class)->withdraw($en, $reason, $u);
        $en->update(['status' => 'excluded', 'result' => 'excluded', 'exclude_reason' => $reason]);
        if ($uid = User::where('employee_id', $en->employee_id)->value('id')) {
            $t = TdSession::whereKey($en->session_id)->value('title');
            Notify::user($uid, "استُبعدت من دورة «{$t}»: {$reason}", "You were excluded from “{$t}”: {$reason}", 'training');
        }
        return $en->fresh();
    }

    // ———————————————— الترشيح من الاحتياجات ————————————————

    /** الموظفون الذين لهم احتياج معتمد يخص بند الخطة أو كفاءات البرنامج ولم يُرشَّحوا بعد */
    public static function candidates(TdSession $s): array
    {
        $p = TdProgram::find($s->program_id);
        $comp = array_column($p?->competencies ?? [], 'id');
        $taken = TdEnrollment::where('session_id', $s->id)->whereNotIn('status', ['withdrawn'])->pluck('employee_id')->all();
        $needs = TdNeed::whereIn('status', ['approved', 'planned'])
            ->where(fn ($q) => $q->when($s->plan_item_id, fn ($x) => $x->orWhere('plan_item_id', $s->plan_item_id))->when($comp, fn ($x) => $x->orWhereIn('competency_id', $comp))->orWhere('program_id', $s->program_id))->get();
        $ids = [];
        foreach ($needs as $n) foreach (array_filter(array_merge([$n->employee_id], (array) ($n->employees ?? []))) as $id) if (! in_array((int) $id, $taken, true)) $ids[(int) $id] ??= $n;
        return Employee::current()->whereIn('id', array_keys($ids))->orderBy('name')->get(['id', 'code', 'name', 'name_en', 'department_id'])
            ->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'need' => ['id' => $ids[$e->id]->id, 'ref' => $ids[$e->id]->ref, 'title' => $ids[$e->id]->title, 'priority' => $ids[$e->id]->priority]])->values()->all();
    }

    // ———————————————— السجل السابق للنظام ————————————————

    public function saveHistory(array $v, User $u, ?TdHistory $h = null): TdHistory
    {
        if (! Td::manages($u)) abort(403);
        Td::scoped($u)->findOrFail($v['employee_id'] ?? $h?->employee_id);
        $data = array_intersect_key($v, array_flip(['employee_id', 'title', 'provider', 'place', 'kind', 'from_on', 'to_on', 'hours', 'cost', 'currency', 'file_id', 'note']));
        if ($h) { $h->update($data); return $h; }
        return TdHistory::create($data + ['created_by' => $u->id]);
    }

    public static function historyRows(Employee $e): array
    {
        return TdHistory::where('employee_id', $e->id)->orderByDesc('from_on')->get()->map(fn ($h) => ['id' => $h->id, 'title' => $h->title, 'provider' => $h->provider, 'place' => $h->place, 'kind' => $h->kind,
            'from' => $h->from_on?->toDateString(), 'to' => $h->to_on?->toDateString(), 'hours' => $h->hours, 'cost' => $h->cost, 'currency' => $h->currency, 'file_id' => $h->file_id, 'note' => $h->note])->values()->all();
    }

    // ———————————————— البريد والروابط ————————————————

    /** نسخة بريدية (إن فُعّلت) من إشعار شخصي */
    public static function mail(?int $userId, string $ar, string $en, string $path, ?string $ref = null, string $actAr = 'فتح', string $actEn = 'Open'): void
    {
        if (! $userId || ! Td::cfg()['email']) return;
        $u = User::find($userId); if (! $u?->email) return;
        $c = Tenant::company();
        try {
            $u->notify(new TrainingMail(['title' => 'التدريب والتطوير', 'body' => $ar, 'action' => $actAr], ['title' => 'Training & development', 'body' => $en, 'action' => $actEn],
                rtrim((string) config('app.frontend_url'), '/').$path, ['ar' => $c?->name_ar ?? '', 'en' => $c?->name_en ?? ''], $ref));
        } catch (\Throwable $e) { report($e); }
    }

    private static function token(string $kind, array $data): string
    {
        $t = Str::random(40);
        Cache::put("td:{$kind}:".hash('sha256', $t), $data + ['company' => Tenant::id()], now()->addDays((int) Td::cfg()['link_days']));
        return $t;
    }

    private static function resolve(string $kind, string $token): array
    {
        $d = Cache::get("td:{$kind}:".hash('sha256', $token));
        if (! $d) throw new ApiException(L::t('الرابط غير صالح أو انتهت صلاحيته.', 'The link is invalid or has expired.'), 'link_invalid', 410);
        Tenant::set($d['company']);
        return $d;
    }

    /** FR-TD-065: رابط موافقة المدير يُرسل بالبريد مع إشعار الترشيح */
    public static function managerLink(TdEnrollment $en): ?string
    {
        $mgr = Employee::whereKey($en->employee_id)->value('manager_id');
        $uid = $mgr ? User::where('employee_id', $mgr)->value('id') : null;
        if (! $uid) return null;
        $t = self::token('mgr', ['enrollment' => $en->id, 'user' => $uid]);
        $s = TdSession::find($en->session_id); $e = Employee::find($en->employee_id);
        self::mail($uid, "رُشّح {$e->name} لدورة «{$s->title}» من {$s->starts_on->toDateString()} — وافق أو ارفض من الرابط.", "{$e->name_en} was nominated for “{$s->title}” from {$s->starts_on->toDateString()} — approve or decline from the link.", "/td-approve/{$t}", null, 'الموافقة أو الرفض', 'Approve or decline');
        return $t;
    }

    public static function managerInfo(string $token): array
    {
        $d = self::resolve('mgr', $token);
        $en = TdEnrollment::with('session:id,title,starts_on,ends_on,place')->findOrFail($d['enrollment']); $e = Employee::find($en->employee_id);
        return ['employee' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => $en->session->title, 'from' => $en->session->starts_on->toDateString(), 'to' => $en->session->ends_on->toDateString(),
            'place' => $en->session->place, 'reason' => $en->reason, 'answered' => $en->manager_ok !== null, 'ok' => $en->manager_ok, 'company' => Tenant::company()?->card()];
    }

    public static function managerAnswer(string $token, bool $ok): array
    {
        $d = self::resolve('mgr', $token);
        $en = TdEnrollment::findOrFail($d['enrollment']);
        if ($en->manager_ok !== null) throw new ApiException(L::t('سُجّل قرارك من قبل.', 'Your decision is already recorded.'), 'answered', 409);
        app(Sessions::class)->managerDecision($en, $ok, User::findOrFail($d['user']));
        Cache::forget('td:mgr:'.hash('sha256', $token));
        return ['answered' => true, 'ok' => $en->fresh()->manager_ok];
    }

    /** توقيع التعهد عن بعد: رابط آمن + رمز تحقق يُرسل إلى بريد الموظف */
    public static function commitLink(TdCommitment $c): ?string
    {
        $uid = User::where('employee_id', $c->employee_id)->value('id'); if (! $uid) return null;
        $t = self::token('commit', ['commitment' => $c->id, 'user' => $uid]);
        self::mail($uid, "تعهد الالتزام لدورة «{$c->title}» جاهز للتوقيع — افتح الرابط واطلب رمز التحقق.", "The training commitment for “{$c->title}” is ready to sign — open the link and request the verification code.", "/td-commit/{$t}", null, 'قراءة التعهد وتوقيعه', 'Read and sign');
        return $t;
    }

    public static function commitInfo(string $token): array
    {
        $d = self::resolve('commit', $token);
        $c = TdCommitment::findOrFail($d['commitment']);
        return ['title' => $c->title, 'body' => $c->body, 'months' => $c->months, 'status' => $c->status, 'signed' => $c->status !== 'generated', 'company' => Tenant::company()?->card(),
            'email' => preg_replace('/(?<=.).(?=[^@]*@)/u', '•', (string) User::whereKey($d['user'])->value('email'))];
    }

    public static function commitCode(string $token): void
    {
        $d = self::resolve('commit', $token);
        $key = 'td:commit-code:'.hash('sha256', $token);
        if (($old = Cache::get($key)) && now()->timestamp - ($old['at'] ?? 0) < 30) throw new ApiException(L::t('انتظر قليلاً قبل طلب رمز جديد.', 'Wait a moment before requesting a new code.'), 'too_soon', 429);
        $code = (string) random_int(100000, 999999);
        Cache::put($key, ['hash' => hash('sha256', $code), 'at' => now()->timestamp, 'tries' => 0], now()->addMinutes(10));
        $u = User::find($d['user']); $c = Tenant::company();
        $u->notify(new TrainingMail(['title' => 'رمز توقيع التعهد', 'body' => 'استخدم هذا الرمز لتوقيع تعهد الالتزام بالتدريب. صالح 10 دقائق.', 'action' => ''], ['title' => 'Commitment signing code', 'body' => 'Use this code to sign the training commitment. Valid for 10 minutes.', 'action' => ''],
            null, ['ar' => $c?->name_ar ?? '', 'en' => $c?->name_en ?? ''], $code));
    }

    public static function commitSign(string $token, string $code, string $signature): array
    {
        $d = self::resolve('commit', $token);
        $key = 'td:commit-code:'.hash('sha256', $token);
        $st = Cache::get($key);
        if (! $st) throw ApiException::field('code', L::t('اطلب رمز التحقق أولاً.', 'Request the verification code first.'), 'code_required');
        if (! hash_equals($st['hash'], hash('sha256', trim($code)))) {
            $st['tries']++; $st['tries'] >= 5 ? Cache::forget($key) : Cache::put($key, $st, now()->addMinutes(10));
            throw ApiException::field('code', L::t('الرمز غير صحيح.', 'Wrong code.'), 'wrong');
        }
        $c = TdCommitment::findOrFail($d['commitment']);
        app(Sessions::class)->signCommitment($c, User::findOrFail($d['user']), $signature, null);
        Cache::forget($key); Cache::forget('td:commit:'.hash('sha256', $token));
        Audit::record('td_commitment.signed_by_link', $c);
        return ['signed' => true];
    }

    // ———————————————— التقويم المشترك ————————————————

    public static function calendarToken(User $u, bool $renew = false): string
    {
        if (! $u->calendar_token || $renew) $u->forceFill(['calendar_token' => Str::random(40)])->save();
        return $u->calendar_token;
    }

    // ———————————————— المتابعة الدورية ————————————————

    /** حملات: تذكير قبل الإغلاق للإدارات التي لم تُرسل، وإغلاق تلقائي بعد التاريخ؛ التنفيذات المغلقة بعد اكتمال قياس الأثر ← «مُقيَّم» */
    public function sweep(): int
    {
        $cfg = Td::cfg(); $n = 0; $needs = app(Needs::class);
        foreach (TdCampaign::where('status', 'open')->get() as $c) {
            if ($cfg['campaign_auto_close'] && $c->closes_on->lt(now()->startOfDay())) {
                $c->update(['status' => 'closed']); $n++;
                $deps = $needs->campaignDepartments($c); $sent = count($c->responses ?? []);
                Notify::hr("أُغلقت حملة الاحتياجات «{$c->title}» — استجابت {$sent} من ".$deps->count().' إدارة', "Needs campaign “{$c->title}” closed — {$sent} of ".$deps->count().' departments responded', 'training:needs');
                continue;
            }
            if (! $c->reminded_at && $c->closes_on->lte(now()->addDays((int) $cfg['campaign_remind_days']))) {
                foreach ($needs->campaignDepartments($c) as $d) if (! isset(($c->responses ?? [])[$d->id]) && $d->head_id && ($uid = User::where('employee_id', $d->head_id)->value('id'))) {
                    Notify::user($uid, "تذكير: لم تُرسل احتياجات إدارتك في حملة «{$c->title}» — تُغلق في {$c->closes_on->toDateString()}", "Reminder: your department's needs for “{$c->title}” are not sent — closes {$c->closes_on->toDateString()}", 'training-needs');
                    self::mail($uid, "لم تُرسل احتياجات إدارتك في حملة «{$c->title}» — تُغلق في {$c->closes_on->toDateString()}.", "Your department's needs for “{$c->title}” are not sent — closes {$c->closes_on->toDateString()}.", '/hr/training?tab=needs&view=campaigns');
                    $n++;
                }
                $c->update(['reminded_at' => now()]);
            }
        }
        foreach (TdSession::where('status', 'closed')->whereNull('evaluated_at')->get() as $s) {
            $ids = TdEnrollment::where('session_id', $s->id)->where('result', 'passed')->pluck('id');
            if ($ids->isNotEmpty() && ! TdImpact::whereIn('enrollment_id', $ids)->where('status', 'pending')->exists()) { $s->update(['status' => 'evaluated', 'evaluated_at' => now()]); $n++; }
        }
        return $n;
    }

    /** نسبة استجابة الحملة */
    public static function campaignRate(TdCampaign $c): array
    {
        $deps = app(Needs::class)->campaignDepartments($c)->count();
        $sent = count($c->responses ?? []);
        return ['departments' => $deps, 'responded' => $sent, 'pct' => $deps ? (int) round($sent * 100 / $deps) : 0];
    }

    /** BR-11: الحملة خارج نافذة التحديد السنوية تحتاج مبرراً عند التفعيل */
    public static function tnaWindow(string $opensOn, ?string $reason): void
    {
        $cfg = Td::cfg();
        if (! $cfg['tna_enforce']) return;
        $m = (int) Carbon::parse($opensOn)->month;
        $in = $cfg['tna_from_month'] <= $cfg['tna_to_month'] ? ($m >= $cfg['tna_from_month'] && $m <= $cfg['tna_to_month']) : ($m >= $cfg['tna_from_month'] || $m <= $cfg['tna_to_month']);
        if (! $in && mb_strlen(trim((string) $reason)) < self::min())
            throw ApiException::field('window_reason', L::t("نافذة تحديد الاحتياجات من الشهر {$cfg['tna_from_month']} إلى {$cfg['tna_to_month']} — اكتب مبرر الحملة خارجها.", "The needs window is month {$cfg['tna_from_month']}–{$cfg['tna_to_month']} — justify a campaign outside it."), 'tna_window');
    }

    /** اكتمال الهدف: السلوك المطلوب، الشرط، والمعيار القابل للقياس (رقم أو نسبة أو مدة) */
    public static function smart(?array $o): array
    {
        $o ??= [];
        $behavior = trim((string) ($o['behavior'] ?? $o['specific'] ?? ''));
        $condition = trim((string) ($o['condition'] ?? $o['achievable'] ?? $o['relevant'] ?? ''));
        $criterion = trim((string) ($o['criterion'] ?? $o['measurable'] ?? ''));
        $time = trim((string) ($o['timebound'] ?? ''));
        $miss = [];
        if (mb_strlen($behavior) < 5) $miss[] = 'behavior';
        if (mb_strlen($condition) < 3) $miss[] = 'condition';
        if (mb_strlen($criterion) < 2 || ! preg_match('/[0-9٠-٩%]/u', $criterion.' '.$time)) $miss[] = 'criterion';
        return $miss;
    }
}
