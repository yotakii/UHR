<?php

namespace App\Services\Missions;

use App\Exceptions\ApiException;
use App\Models\CodeItem;
use App\Models\Employee;
use App\Models\Mission;
use App\Models\MissionAmend;
use App\Models\MissionInvite;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;

/**
 * الدفعة 28 — إكمال المهمات (الجزء B): سلسلة الموافقة والمراحل حسب نوع المهمة، نطاق الوجهة (منطقة) بمعامل على البدل،
 * حدود الإقامة حسب المدينة ودرجة التذكرة حسب الدرجة، تعديل مهمة معتمدة بموافقة على الفرق، دعوة عمل بموافقة مستقلة،
 * الإشارة إلى المستخدمين في التعليقات، وأتعاب المحاضر الداخلي من موديول التدريب.
 */
class MissionExtras
{
    public const TICKETS = ['economy', 'premium', 'business', 'first'];

    public function __construct(private Workflow $wf) {}

    /** سياق اختيار السلسلة: نوع المهمة، الكلفة التقديرية (بعملة السلسلة)، الدرجة، الفرع */
    public static function chainContext(Mission $m): array
    {
        $tot = $m->estimate['totals'] ?? [];
        $cur = array_key_first($tot);
        return ['sub' => $m->type?->code, 'amount' => $cur ? (float) $tot[$cur] : 0, 'currency' => $cur, 'grade' => $m->employee?->grade_id ? CodeItem::find($m->employee->grade_id)?->code : null,
            'branch' => $m->employee?->location_id];
    }

    /** مراحل التنفيذ من خاصية النوع «اسم:وزن، …»، وإلا مراحل الإعدادات */
    public static function stagesFor(?CodeItem $type): ?array
    {
        $raw = trim((string) $type?->attr('stages', ''));
        if ($raw === '') return null;
        $out = [];
        foreach (preg_split('/[،,]/u', $raw) as $p) {
            [$n, $w] = array_pad(array_map('trim', explode(':', $p, 2)), 2, null);
            if ($n !== '') $out[] = ['name' => $n, 'en' => $n, 'weight' => max(0, (int) $w), 'done_at' => null];
        }
        $sum = array_sum(array_column($out, 'weight'));
        if (! $out) return null;
        if ($sum !== 100) foreach ($out as $i => &$s) $s['weight'] = $i === count($out) - 1 ? 100 - (int) floor(100 / count($out)) * (count($out) - 1) : (int) floor(100 / count($out));
        return $out;
    }

    /** منطقة الوجهة (FR-TM-009): [key, factor] من جدول المناطق (محافظات أو دول) */
    public static function zone(array $t): ?array
    {
        foreach (Missions::cfg()['zones'] ?? [] as $z) {
            $hit = ($t['scope'] ?? 'local') === 'foreign' ? in_array($t['country'] ?? '', $z['countries'] ?? [], true) : in_array($t['governorate'] ?? '', $z['governorates'] ?? [], true);
            if ($hit) return $z;
        }
        return null;
    }

    /** حد الإقامة لمدينة/دولة محددة إن وُجد (FR-TM-013) */
    public static function hotelLimit(array $t, ?string $grade): ?array
    {
        $city = mb_strtolower(trim((string) ($t['city'] ?? '')));
        foreach (Missions::cfg()['hotel_cities'] ?? [] as $h) {
            $match = ($h['city'] ?? '') !== '' ? $city !== '' && mb_strtolower(trim($h['city'])) === $city : (($h['country'] ?? '') !== '' && ($h['country'] === ($t['country'] ?? '')) || (($h['governorate'] ?? '') !== '' && $h['governorate'] === ($t['governorate'] ?? '')));
            if ($match && (empty($h['grades']) || in_array($grade, $h['grades'], true))) return $h;
        }
        return null;
    }

    /** إضافات على بطاقة التقدير: المنطقة، حد المدينة، التذكرة، التعارض مع التدريب، الخبير */
    public static function adjust(Mission $m, array $out): array
    {
        $t = $m->travel ?? [];
        if ($m->external_id) {
            $out['lines'] = []; $out['totals'] = [];
            $out['warnings'][] = ['key' => 'external', 'ar' => 'المسافر خبير خارجي: مستحقاته من عقده لا من جدول بدلات الموظفين', 'en' => "The traveller is an external expert: paid under the contract, not the staff allowance table"];
            return $out;
        }
        if ($z = self::zone($t)) {
            $f = (float) ($z['factor'] ?? 1);
            foreach ($out['lines'] as &$l) if (in_array($l['code'], ['E-110', 'E-111'], true) && $f !== 1.0) { $l['rate'] = round($l['rate'] * $f, 2); $l['amount'] = round($l['qty'] * $l['rate'], 2); $l['zone'] = $z['key']; }
            unset($l);
            $out['zone_name'] = ['ar' => $z['ar'] ?? $z['key'], 'en' => $z['en'] ?? $z['key']];
        }
        if (($h = self::hotelLimit($t, $out['grade'] ?? null)) !== null) {
            foreach ($out['lines'] as &$l) if ($l['code'] === 'E-130') { $l['rate'] = (float) $h['limit']; $l['amount'] = round($l['qty'] * $l['rate'], 2); $l['currency'] = $h['currency'] ?? $l['currency']; $l['city_limit'] = true; }
            unset($l);
        }
        if ($m->employee_id && ($from = $t['depart_on'] ?? $m->starts_on?->toDateString()) && ($to = $t['return_on'] ?? $m->due_on?->toDateString())
            && \App\Models\TdEnrollment::where('employee_id', $m->employee_id)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed'])
                ->whereHas('session', fn ($q) => $q->whereNotIn('status', ['cancelled', 'closed'])->where('starts_on', '<=', $to)->where('ends_on', '>=', $from))->exists())
            $out['warnings'][] = ['key' => 'training', 'ar' => 'تتعارض مع دورة تدريبية مسجّل فيها الموظف', 'en' => 'Overlaps a training course of the employee'];
        $allowed = $out['ticket'] ?? null;
        if ($allowed && $m->ticket_class && array_search($m->ticket_class, self::TICKETS, true) > array_search($allowed, self::TICKETS, true))
            $out['warnings'][] = ['key' => 'ticket_over', 'ar' => "درجة التذكرة المطلوبة أعلى من المسموح لدرجة الموظف ({$allowed})", 'en' => "The requested ticket class is above the grade allowance ({$allowed})"];
        $out['totals'] = collect($out['lines'])->groupBy('currency')->map(fn ($g) => round($g->sum('amount'), 2))->all();
        return $out;
    }

    /** منع درجة تذكرة أعلى من المسموح حين يكون الإعداد «منع» */
    public static function enforceTicket(Mission $m): void
    {
        if ((Missions::cfg()['ticket_over'] ?? 'warn') !== 'block') return;
        if (collect($m->estimate['warnings'] ?? [])->firstWhere('key', 'ticket_over'))
            throw ApiException::field('ticket_class', L::t('درجة التذكرة أعلى من المسموح لدرجة الموظف.', 'The ticket class is above the grade allowance.'), 'ticket_over');
    }

    // ———————————————— تعديل مهمة معتمدة (FR-TM-024) ————————————————

    public function requestAmend(Mission $m, User $u, string $depart, string $return, string $reason, array $estimateAfter): MissionAmend
    {
        if (MissionAmend::where('mission_id', $m->id)->whereNotIn('status', ['approved', 'rejected', 'cancelled'])->exists())
            throw new ApiException(L::t('يوجد طلب تعديل قائم لهذه المهمة.', 'An amendment request is already open.'), 'pending', 409);
        $a = MissionAmend::create(['mission_id' => $m->id, 'depart_on' => $depart, 'return_on' => $return, 'reason' => $reason, 'before' => $m->estimate, 'after' => $estimateAfter, 'status' => 'pending_manager', 'requested_by' => $u->id]);
        $this->wf->start($a, $m->employee, Workflow::chainFor('mission_amend', self::chainContext($m)), $u);
        $a->refresh();
        if ($a->status === 'approved') $this->applyAmend($a, $u);
        Missions::log($m, $u, 'amend_requested', "{$depart} → {$return}: {$reason}");
        return $a;
    }

    public function decideAmend(MissionAmend $a, User $u, bool $ok, ?string $note): MissionAmend
    {
        return $this->wf->decide($a, $u, $ok, $note, false, fn (MissionAmend $x) => $this->applyAmend($x, $u))->fresh();
    }

    private function applyAmend(MissionAmend $a, User $u): void
    {
        $m = Mission::with(['type', 'employee'])->findOrFail($a->mission_id);
        $s = app(Missions::class);
        $s->markDays($m, false);
        $m->travel = array_merge($m->travel ?? [], ['depart_on' => $a->depart_on->toDateString(), 'return_on' => $a->return_on->toDateString()]);
        $m->update(['travel' => $m->travel, 'estimate' => $s->estimate($m), 'due_on' => $a->return_on->toDateString(), 'close_due' => Missions::closeDue($a->return_on->toDateString())]);
        $s->markDays($m, true);
        Missions::log($m, $u, 'amended', $a->depart_on->toDateString().' → '.$a->return_on->toDateString().': '.$a->reason);
    }

    // ———————————————— دعوة العمل (FR-TM-026) ————————————————

    public function invite(Mission $m, User $u, array $v): MissionInvite
    {
        if (! Missions::isOwner($u, $m) && ! Missions::isExecutor($u, $m)) abort(403);
        if (in_array($m->status, ['cancelled', 'rejected', 'closed'], true)) throw new ApiException(L::t('المهمة منتهية.', 'The mission is closed.'), 'locked', 409);
        $i = MissionInvite::create(['mission_id' => $m->id, 'guest_name' => trim($v['guest_name']), 'organization' => $v['organization'] ?? null, 'nationality' => $v['nationality'] ?? null, 'passport' => $v['passport'] ?? null,
            'purpose' => trim($v['purpose']), 'from_on' => $v['from_on'], 'to_on' => $v['to_on'], 'status' => 'pending_hr', 'requested_by' => $u->id]);
        $this->wf->start($i, $m->employee, Workflow::chainFor('work_invite', self::chainContext($m)), $u);
        $i->refresh();
        if ($i->status === 'approved') $this->invited($i);
        Missions::log($m, $u, 'invite_requested', $i->guest_name);
        return $i;
    }

    public function decideInvite(MissionInvite $i, User $u, bool $ok, ?string $note): MissionInvite
    {
        return $this->wf->decide($i, $u, $ok, $note, false, fn (MissionInvite $x) => $this->invited($x))->fresh();
    }

    private function invited(MissionInvite $i): void
    {
        $i->update(['letter_no' => 'INV-'.now()->year.'-'.str_pad((string) Counters::next('invite-'.now()->year), 4, '0', STR_PAD_LEFT)]);
        Audit::record('mission.invite_approved', $i);
    }

    public static function inviteRow(MissionInvite $i, User $viewer): array
    {
        $cur = Workflow::current($i); $m = Mission::find($i->mission_id);
        return ['kind' => 'mission_invite', 'id' => $i->id, 'type' => 'work_invite', 'type_name' => ['ar' => 'دعوة عمل', 'en' => 'Work invitation'], 'employee' => null,
            'from' => $i->from_on->toDateString(), 'to' => $i->to_on->toDateString(), 'status' => $i->status, 'reason' => $i->purpose,
            'invite' => ['guest' => $i->guest_name, 'organization' => $i->organization, 'nationality' => $i->nationality, 'mission' => ['id' => $m?->id, 'ref' => $m?->ref_no, 'title' => $m?->title], 'letter_no' => $i->letter_no],
            'warnings' => [], 'requested_by' => null, 'created_at' => $i->created_at?->toIso8601String(), 'steps' => Workflow::steps($i), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $i) : false, 'can_cancel' => false];
    }

    public static function amendRow(MissionAmend $a, User $viewer): array
    {
        $cur = Workflow::current($a); $m = Mission::with('employee')->find($a->mission_id);
        $diff = [];
        foreach (($a->after['totals'] ?? []) as $c => $v) $diff[$c] = round($v - (float) ($a->before['totals'][$c] ?? 0), 2);
        return ['kind' => 'mission_amend', 'id' => $a->id, 'type' => 'mission_amend', 'type_name' => ['ar' => 'تعديل مهمة', 'en' => 'Mission amendment'],
            'employee' => $m?->employee ? ['id' => $m->employee->id, 'code' => $m->employee->code, 'ar' => $m->employee->name, 'en' => $m->employee->name_en] : null,
            'from' => $a->depart_on->toDateString(), 'to' => $a->return_on->toDateString(), 'status' => $a->status, 'reason' => $a->reason,
            'amend' => ['mission' => ['id' => $m?->id, 'ref' => $m?->ref_no, 'title' => $m?->title], 'diff' => \App\Services\Security\SalaryVault::open($viewer) ? $diff : null],
            'warnings' => [], 'requested_by' => null, 'created_at' => $a->created_at?->toIso8601String(), 'steps' => Workflow::steps($a), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $a) : false, 'can_cancel' => false];
    }

    // ———————————————— الإشارات في التعليقات (FR-TM-006) ————————————————

    /** @[الاسم](رقم المستخدم) — يُرسل إشعار لكل مُشار إليه من شركة المهمة */
    public static function mentions(string $text, Mission $m, User $by): array
    {
        preg_match_all('/@\[[^\]]{1,80}\]\((\d+)\)/u', $text, $mm);
        $ids = User::where('company_id', $by->company_id)->whereIn('id', array_map('intval', $mm[1] ?? []))->pluck('id')->all();
        foreach ($ids as $id) if ($id !== $by->id) Notify::user($id, "أشار إليك {$by->displayName('ar')} في المهمة {$m->ref_no}", "{$by->displayName('en')} mentioned you on mission {$m->ref_no}", 'mission:'.$m->id);
        return $ids;
    }

    // ———————————————— أتعاب المحاضر الداخلي (FR-TM-021) ————————————————

    public static function trainerFee(\App\Models\TdSession $s): ?\App\Models\Bonus
    {
        $tr = $s->trainer_id ? \App\Models\TdTrainer::find($s->trainer_id) : null;
        if (! $tr || $tr->kind !== 'internal' || ! $tr->employee_id) return null;
        $e = Employee::find($tr->employee_id); if (! $e) return null;
        $hours = (float) (\App\Models\TdProgram::whereKey($s->program_id)->value('hours') ?? 0);
        $cfg = Missions::cfg();
        $rate = (float) ($tr->hourly_rate ?: ($cfg['trainer_rates'][$cfg['trainer_default_level'] ?? 'intermediate'] ?? 0));
        if ($hours <= 0 || $rate <= 0) return null;
        $type = \App\Services\Codes\Codes::resolve('bonus_types', 'BN-40');
        if (! $type) return null;
        try {
            return app(Bonuses::class)->create(auth()->user(), $e, $type,
                ['hours' => $hours, 'rate' => $rate, 'currency' => $tr->currency ?: $cfg['trainer_currency']], "أتعاب محاضر داخلي — {$s->title} ({$s->ref})", 'td_session', $s->id);
        } catch (ApiException) { return null; }
    }
}
