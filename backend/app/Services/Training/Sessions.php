<?php

namespace App\Services\Training;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\TdCenter;
use App\Models\TdCertificate;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdPlanItem;
use App\Models\TdProgram;
use App\Models\TdRating;
use App\Models\TdSession;
use App\Models\TdSurvey;
use App\Models\TdTrainer;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Signature;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * التنفيذ (WF-03/04): الانعقاد من برنامج، الترشيح بفحص الأهلية والتعارض، التعهد F-03 قبل التأكيد، الحضور F-08 (يدوي أو QR)،
 * الاختبار القبلي والبعدي، النتيجة (BR-02) ومسار غير الناجح، الشهادات برمز تحقق، الاستبيان F-07، تقييم المدرب والمركز F-13،
 * خطة التطبيق F-12 ومهامها، وقياس الأثر 30-60-90 (F-05) الذي يحدّث مستوى الكفاءة.
 */
class Sessions
{
    private static function min(): int { return (int) Settings::policy('leave_rules')['reject_min']; }
    public const ATT = ['present', 'absent', 'late', 'excused'];

    public function save(array $v, User $u, ?TdSession $s = null): array
    {
        if (! Td::manages($u)) abort(403);
        if ($s && in_array($s->status, ['completed', 'closed', 'cancelled'], true)) throw new ApiException(L::t('لا يُعدَّل تنفيذ مكتمل.', 'A completed run cannot change.'), 'locked', 409);
        $p = TdProgram::findOrFail($v['program_id'] ?? $s?->program_id);
        $meetings = array_values(array_map(fn ($m) => ['date' => $m['date'], 'from' => $m['from'] ?? null, 'to' => $m['to'] ?? null], $v['meetings'] ?? []));
        if (! $meetings) throw ApiException::field('meetings', L::t('أضف جلسة واحدة على الأقل.', 'Add at least one meeting.'), 'required');
        usort($meetings, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $data = ['program_id' => $p->id, 'plan_item_id' => $v['plan_item_id'] ?? $s?->plan_item_id, 'title' => $v['title'] ?? $p->title, 'meetings' => $meetings, 'starts_on' => $meetings[0]['date'], 'ends_on' => end($meetings)['date'],
            'trainer_id' => $v['trainer_id'] ?? null, 'center_id' => $v['center_id'] ?? null, 'room_id' => $v['room_id'] ?? null, 'location_id' => $v['location_id'] ?? null, 'place' => $v['place'] ?? null, 'link' => $v['link'] ?? null,
            'capacity' => (int) ($v['capacity'] ?? 20), 'costs' => $v['costs'] ?? [], 'currency' => $v['currency'] ?? 'SYP', 'quotes' => $v['quotes'] ?? $s?->quotes, 'quote_choice' => $v['quote_choice'] ?? $s?->quote_choice, 'quote_reason' => $v['quote_reason'] ?? $s?->quote_reason];
        foreach (['trainer_id' => TdTrainer::class, 'center_id' => TdCenter::class] as $k => $cls) if ($data[$k] && ($x = $cls::find($data[$k])) && $x->status === 'banned')
            throw ApiException::field($k, L::t('هذه الجهة محظورة بقرار من الموارد البشرية.', 'This provider is banned by HR.'), 'banned');
        // FR-TD-075: لا تُحجز قاعة محجوزة في نفس الوقت (التعارض مع المدرب تحذير)
        $probe = new TdSession($data); $probe->id = $s?->id; $probe->starts_on = $data['starts_on']; $probe->ends_on = $data['ends_on'];
        if (collect(TdExtras::clashes($probe))->firstWhere('key', 'room_clash') && empty($v['force']))
            throw ApiException::field('room_id', collect(TdExtras::clashes($probe))->firstWhere('key', 'room_clash')[app()->getLocale() === 'en' ? 'en' : 'ar'], 'room_clash');
        if ($s && $s->quote_status && json_encode($data['quotes'] ?? []) !== json_encode($s->quotes ?? [])) $data['quote_status'] = null;   // تغيّرت العروض ← يعاد الاعتماد
        if ($s?->status === 'postponed') $data['status'] = TdEnrollment::where('session_id', $s->id)->whereNotIn('status', ['withdrawn', 'excluded'])->exists() ? 'enrolling' : 'planned';
        if ($s) $s->update($data);
        else $s = TdSession::create($data + ['ref' => 'TS-'.now()->year.'-'.str_pad((string) Counters::next('td-session-'.now()->year), 4, '0', STR_PAD_LEFT), 'status' => 'planned',
            'logistics' => array_map(fn ($i) => ['item' => $i, 'done' => false], Td::cfg()['logistics']), 'survey_token' => Str::random(32), 'created_by' => $u->id]);
        if ($s->plan_item_id) TdPlanItem::whereKey($s->plan_item_id)->where('status', 'planned')->update(['status' => 'confirmed']);
        return ['session' => $s->fresh(), 'warnings' => self::warnings($s, $p)];
    }

    /** BR-04 السعة حسب الأسلوب + BR-05 عروض التدريب الخارجي */
    public static function warnings(TdSession $s, ?TdProgram $p = null): array
    {
        $p ??= TdProgram::find($s->program_id); $cfg = Td::cfg(); $w = [];
        [$lo, $hi] = $cfg['capacity'][$p->method] ?? $cfg['capacity']['general'];
        $n = TdEnrollment::where('session_id', $s->id)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed'])->count();
        if ($s->capacity > $hi) $w[] = ['key' => 'capacity_high', 'ar' => "السعة أكبر من الحد المنصوح لهذا الأسلوب ({$hi})", 'en' => "Capacity above the recommended maximum for this method ({$hi})"];
        if ($n > 0 && $n < $lo) $w[] = ['key' => 'too_few', 'ar' => "عدد المتدربين أقل من الحد الأدنى ({$lo})", 'en' => "Fewer trainees than the minimum ({$lo})"];
        if (in_array($p->delivery, ['external', 'internal_ext'], true) && count($s->quotes ?? []) < (int) $cfg['min_quotes'] && mb_strlen(trim((string) $s->quote_reason)) < 10)
            $w[] = ['key' => 'quotes', 'ar' => "التدريب الخارجي يحتاج {$cfg['min_quotes']} عروض أو مبرراً", 'en' => "External training needs {$cfg['min_quotes']} quotes or a justification"];
        if (count($s->quotes ?? []) && $s->quote_status !== 'approved' && $cfg['quote_gate']) $w[] = ['key' => 'quote_approval', 'ar' => 'اختيار العرض بانتظار الاعتماد قبل تأكيد التسجيل', 'en' => 'The quote choice needs approval before confirming places'];
        return array_merge($w, TdExtras::clashes($s));
    }

    public static function totalCost(TdSession $s): float
    {
        // سعر الصرف بتاريخ الإنفاق (بداية التنفيذ) لا بتاريخ اليوم
        return (float) collect($s->costs ?? [])->sum(fn ($c) => Td::convert((float) ($c['amount'] ?? 0), $c['currency'] ?? $s->currency, $s->currency, $c['date'] ?? $s->starts_on?->toDateString()));
    }

    // ———————————————— الترشيح والتعهد ————————————————

    /** @return array{added: int, waitlist: int, blocked: array} */
    public function nominate(TdSession $s, array $ids, ?string $reason, ?string $exception, User $u, ?int $needId = null): array
    {
        $self = count($ids) === 1 && (int) $ids[0] === (int) $u->employee_id && ! Td::manages($u);
        if (! Td::manages($u) && ! $self) foreach ($ids as $id) if (! Td::inScope($u, (int) $id)) abort(403);
        if (! in_array($s->status, ['planned', 'enrolling', 'confirmed'], true)) throw new ApiException(L::t('التسجيل مغلق.', 'Enrolment is closed.'), 'closed', 409);
        $p = TdProgram::findOrFail($s->program_id); $out = ['added' => 0, 'waitlist' => 0, 'blocked' => []];
        foreach (Employee::whereIn('id', $ids)->get() as $e) {
            if (TdEnrollment::where('session_id', $s->id)->where('employee_id', $e->id)->whereNotIn('status', ['withdrawn'])->exists()) continue;
            $el = Td::eligibility($e, $s, $p);
            if ($el['block'] && mb_strlen(trim((string) $exception)) < self::min()) { $out['blocked'][] = ['id' => $e->id, 'ar' => $e->name, 'en' => $e->name_en, 'reasons' => $el['block']]; continue; }
            $taken = TdEnrollment::where('session_id', $s->id)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed'])->count();
            $need = $needId ?: TdNeed::where('employee_id', $e->id)->whereIn('status', ['approved', 'planned'])->when($p->competencies, fn ($q) => $q->whereIn('competency_id', array_column($p->competencies, 'id')))->value('id');
            TdEnrollment::updateOrCreate(['session_id' => $s->id, 'employee_id' => $e->id], ['need_id' => $need, 'status' => $taken >= $s->capacity ? 'waitlist' : 'nominated', 'reason' => $reason, 'exception' => $el['block'] ? $exception : null,
                'manager_ok' => null, 'attendance' => null, 'result' => null]);
            $taken >= $s->capacity ? $out['waitlist']++ : $out['added']++;
            if ($e->manager_id && ($mu = User::where('employee_id', $e->manager_id)->value('id'))) {
                Notify::user($mu, "ترشيح {$e->name} لدورة «{$s->title}» — يرجى الموافقة", "{$e->name_en} nominated for “{$s->title}” — please approve", 'training-team');
                TdExtras::managerLink(TdEnrollment::where('session_id', $s->id)->where('employee_id', $e->id)->first());
            }
        }
        if ($s->status === 'planned') $s->update(['status' => 'enrolling']);
        return $out;
    }

    /** FR-TD-065: موافقة المدير المباشر على الترشيح */
    public function managerDecision(TdEnrollment $en, bool $ok, User $u): TdEnrollment
    {
        $e = Employee::find($en->employee_id);
        if (! Td::manages($u) && ($u->employee_id === null || $u->employee_id !== $e->manager_id)) abort(403);
        $en->update(['manager_ok' => $ok, 'manager_at' => now()] + ($ok ? [] : ['status' => 'withdrawn', 'reason' => trim(($en->reason ?? '').' — رفض المدير')]));
        if (! $ok) $this->promote($en->session_id);
        return $en;
    }

    /** تأكيد التسجيل — يُولَّد التعهد أولاً إن لزم (BR-06) ولا يُؤكَّد قبل توقيعه */
    public function confirm(TdEnrollment $en, User $u): TdEnrollment
    {
        if (! Td::manages($u)) abort(403);
        if (! in_array($en->status, ['nominated', 'waitlist'], true)) throw new ApiException(L::t('لا يمكن تأكيد هذا التسجيل.', 'This enrolment cannot be confirmed.'), 'invalid', 409);
        $s = TdSession::findOrFail($en->session_id);
        TdExtras::quoteGate($s); $p = TdProgram::find($s->program_id); $e = Employee::findOrFail($en->employee_id);
        $count = max(1, TdEnrollment::where('session_id', $s->id)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed'])->count());
        $cost = round(self::totalCost($s) / $count, 2);
        $requested = TdNeed::whereKey($en->need_id)->where('source', 'request')->exists();
        $months = Td::commitmentFor($e, $cost, $s->currency, $requested);
        if ($months) {
            $cfg = Td::cfg();
            $c = TdCommitment::create(['employee_id' => $e->id, 'enrollment_id' => $en->id, 'title' => $s->title, 'cost' => $cost, 'currency' => $s->currency, 'months' => $months, 'mode' => $cfg['recovery'], 'status' => 'generated',
                'body' => self::commitmentText($e, $s, $cost, $months, $cfg['recovery'])]);
            $en->update(['status' => 'pending_commitment', 'cost' => $cost]);
            if ($uid = User::where('employee_id', $e->id)->value('id')) Notify::user($uid, "وقّع تعهد الالتزام لدورة «{$s->title}» لتأكيد تسجيلك", "Sign the training commitment for “{$s->title}” to confirm your place", 'training');
            TdExtras::commitLink($c);
            Audit::record('td_commitment.generated', $c);
        } else {
            $en->update(['status' => 'confirmed', 'cost' => $cost]);
            $this->markDays($en, true);
            if ($uid = User::where('employee_id', $e->id)->value('id')) {
                Notify::user($uid, "تأكد تسجيلك في «{$s->title}» من {$s->starts_on->toDateString()}", "You are confirmed for “{$s->title}” from {$s->starts_on->toDateString()}", 'training');
                TdExtras::mail($uid, "تأكد تسجيلك في «{$s->title}» من {$s->starts_on->toDateString()}".($s->place ? " — {$s->place}" : '').'.', "You are confirmed for “{$s->title}” from {$s->starts_on->toDateString()}.", '/me/training');
            }
        }
        return $en->fresh();
    }

    public static function commitmentText(Employee $e, TdSession $s, float $cost, int $months, string $mode): string
    {
        $cfg = Td::cfg(); $co = \App\Models\Company::find($e->company_id);
        $out = [];
        foreach (['ar', 'en'] as $l) $out[] = strtr($cfg['commitment_'.$l], [':name' => $l === 'en' ? $e->name_en : $e->name, ':course' => $s->title, ':from' => $s->starts_on->toDateString(), ':to' => $s->ends_on->toDateString(),
            ':company' => $l === 'en' ? $co?->name_en : $co?->name_ar, ':months' => (string) $months, ':cost' => number_format($cost, 2).' '.$s->currency,
            ':mode' => $mode === 'full' ? ($l === 'en' ? 'in full' : 'كاملة') : ($l === 'en' ? 'in proportion to the remaining months' : 'بنسبة الأشهر المتبقية')]);
        return implode("\n\n———\n\n", $out);
    }

    /** FR-TD-061: توقيع إلكتروني من حساب الموظف (ختم زمني وعنوان IP) أو رفع نسخة موقعة */
    public function signCommitment(TdCommitment $c, User $u, ?string $signature, ?int $fileId): TdCommitment
    {
        $own = $u->employee_id && $u->employee_id === $c->employee_id;
        if (! $own && ! Td::manages($u)) abort(403);
        if ($c->status !== 'generated') throw new ApiException(L::t('وُقّع التعهد من قبل.', 'Already signed.'), 'signed', 409);
        $file = $signature ? Signature::store($signature)->id : $fileId;
        if (! $file) throw ApiException::field('signature', L::t('التوقيع مطلوب.', 'A signature is required.'), 'required');
        $en = TdEnrollment::find($c->enrollment_id); $s = $en ? TdSession::find($en->session_id) : null;
        $start = $s?->ends_on ?? now();
        DB::transaction(function () use ($c, $file, $en, $start) {
            $c->update(['status' => 'active', 'signature_file_id' => $file, 'signed_at' => now(), 'signed_ip' => request()?->ip(), 'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addMonths($c->months)->toDateString()]);
            if ($en && $en->status === 'pending_commitment') { $en->update(['status' => 'confirmed']); $this->markDays($en, true); }
        });
        Audit::record('td_commitment.signed', $c, ['paper' => ! $own]);
        return $c->fresh();
    }

    public function withdraw(TdEnrollment $en, ?string $reason, User $u): TdEnrollment
    {
        $own = $u->employee_id && $u->employee_id === $en->employee_id;
        if (! $own && ! Td::manages($u)) abort(403);
        if (! in_array($en->status, ['nominated', 'pending_commitment', 'confirmed', 'waitlist'], true)) throw new ApiException(L::t('لا يمكن الانسحاب الآن.', 'Cannot withdraw now.'), 'invalid', 409);
        $en->update(['status' => 'withdrawn', 'result' => 'withdrawn', 'result_reason' => $reason]);
        TdCommitment::where('enrollment_id', $en->id)->where('status', 'generated')->update(['status' => 'cancelled']);
        $this->markDays($en, false);
        $this->promote($en->session_id);
        return $en;
    }

    /** FR-TD-066: ترقية أول من في قائمة الانتظار */
    private function promote(int $sessionId): void
    {
        $s = TdSession::find($sessionId);
        $taken = TdEnrollment::where('session_id', $sessionId)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed'])->count();
        if ($s && $taken < $s->capacity && ($w = TdEnrollment::where('session_id', $sessionId)->where('status', 'waitlist')->orderBy('id')->first())) {
            $w->update(['status' => 'nominated']);
            if ($uid = User::where('employee_id', $w->employee_id)->value('id')) Notify::user($uid, "توفّر لك مقعد في «{$s->title}»", "A place opened for you in “{$s->title}”", 'training');
        }
    }

    /** FR-TD-084: أيام التدريب «مهمة تدريب» في الحضور لا غياباً */
    public function markDays(TdEnrollment $en, bool $on): void
    {
        $s = TdSession::find($en->session_id); if (! $s) return;
        foreach ($s->meetings as $m) {
            $r = AttendanceDay::where('employee_id', $en->employee_id)->whereDate('date', $m['date'])->first();
            if ($on) { if ($r && ! $r->in_at) $r->update(['status' => 'training', 'td_session_id' => $s->id]); elseif (! $r) AttendanceDay::create(['employee_id' => $en->employee_id, 'date' => $m['date'], 'status' => 'training', 'td_session_id' => $s->id]); }
            elseif ($r && $r->td_session_id === $s->id) { if ($r->in_at) $r->update(['status' => 'present', 'td_session_id' => null]); else $r->delete(); }
        }
    }

    // ———————————————— التنفيذ ————————————————

    /** F-08: حضور جلسة لكل متدرب — حاضر، غائب، متأخر، معذور — ونسبة الحضور محسوبة */
    public function attendance(TdSession $s, int $meeting, array $marks, User $u): TdSession
    {
        if (! Td::manages($u) && ! self::isTrainer($u, $s)) abort(403);
        if (! isset($s->meetings[$meeting])) abort(404);
        if (in_array($s->status, ['closed', 'cancelled'], true)) throw new ApiException(L::t('التنفيذ مغلق.', 'This run is closed.'), 'locked', 409);
        foreach (TdEnrollment::where('session_id', $s->id)->where('status', 'confirmed')->get() as $en) {
            if (! array_key_exists($en->id, $marks) && ! array_key_exists((string) $en->id, $marks)) continue;
            $st = $marks[$en->id] ?? $marks[(string) $en->id];
            if (! in_array($st, self::ATT, true)) continue;
            $a = $en->attendance ?? []; $a[$meeting] = $st;
            $en->update(['attendance' => $a, 'attendance_pct' => self::pct($a, count($s->meetings))]);
        }
        if ($s->status === 'enrolling' || $s->status === 'planned' || $s->status === 'confirmed') $s->update(['status' => 'in_progress']);
        return $s->fresh();
    }

    public static function pct(array $a, int $n): float
    {
        $ok = count(array_filter($a, fn ($v) => in_array($v, ['present', 'late'], true)));
        return $n ? round($ok * 100 / $n, 1) : 0.0;
    }

    public static function isTrainer(User $u, TdSession $s): bool
    {
        return $u->employee_id && $s->trainer_id && TdTrainer::whereKey($s->trainer_id)->where('employee_id', $u->employee_id)->exists();
    }

    /** FR-TD-081: تسجيل الحضور بمسح QR من هاتف المتدرب في يوم الجلسة */
    public function checkin(string $token, User $u): array
    {
        $s = TdSession::where('survey_token', $token)->firstOrFail();
        $en = TdEnrollment::where('session_id', $s->id)->where('employee_id', $u->employee_id ?? 0)->where('status', 'confirmed')->first();
        if (! $en) throw new ApiException(L::t('لست مسجلاً في هذه الدورة.', 'You are not enrolled in this course.'), 'not_enrolled', 403);
        $today = now()->toDateString();
        $idx = collect($s->meetings)->search(fn ($m) => $m['date'] === $today);
        if ($idx === false) throw new ApiException(L::t('لا توجد جلسة اليوم.', 'There is no meeting today.'), 'no_meeting', 422);
        $m = $s->meetings[$idx]; $cfg = Td::cfg();
        // نافذة الحضور بالرمز حول وقت الجلسة
        if ($m['from'] && (now()->format('H:i') < Carbon::parse($m['from'])->subMinutes((int) $cfg['checkin_before'])->format('H:i')
            || now()->format('H:i') > Carbon::parse($m['to'] ?? $m['from'])->addMinutes((int) $cfg['checkin_after'])->format('H:i')))
            throw new ApiException(L::t('تسجيل الحضور بالرمز متاح حول وقت الجلسة فقط.', 'QR check-in is open only around the meeting time.'), 'checkin_closed', 409);
        $late = $m['from'] && now()->format('H:i') > Carbon::parse($m['from'])->addMinutes((int) $cfg['late_after'])->format('H:i');
        $a = $en->attendance ?? []; $a[$idx] = $late ? 'late' : 'present';
        $en->update(['attendance' => $a, 'attendance_pct' => self::pct($a, count($s->meetings))]);
        if (in_array($s->status, ['enrolling', 'planned', 'confirmed'], true)) $s->update(['status' => 'in_progress']);
        return ['title' => $s->title, 'meeting' => $idx + 1, 'status' => $a[$idx]];
    }

    public function scores(TdSession $s, array $rows, User $u): TdSession
    {
        if (! Td::manages($u) && ! self::isTrainer($u, $s)) abort(403);
        foreach ($rows as $r) TdEnrollment::where('session_id', $s->id)->whereKey($r['id'])->update(array_filter(['pre_score' => $r['pre'] ?? null, 'post_score' => $r['post'] ?? null], fn ($v) => $v !== null));
        return $s->fresh();
    }

    /** الإكمال: النتيجة لكل متدرب (BR-02)، الشهادات (BR-14)، جدولة قياس الأثر، إشعار خطة التطبيق F-12 */
    public function complete(TdSession $s, User $u): TdSession
    {
        if (! Td::manages($u)) abort(403);
        if (! in_array($s->status, ['in_progress', 'enrolling', 'confirmed'], true)) throw new ApiException(L::t('لا يمكن إكمال هذا التنفيذ.', 'This run cannot be completed.'), 'invalid', 409);
        TdExtras::quoteGate($s);
        $p = TdProgram::find($s->program_id); $cfg = Td::cfg();
        DB::transaction(function () use ($s, $p, $cfg) {
            foreach (TdEnrollment::where('session_id', $s->id)->where('status', 'confirmed')->get() as $en) {
                [$res, $why] = Td::result($en, $p);
                $en->update(['status' => 'completed', 'result' => $res, 'result_reason' => $why, 'failure' => $res === 'failed' ? ['decision' => null] : null]);
                if ($res === 'passed') {
                    $type = $p->has_test && $en->post_score !== null && $en->post_score >= (float) $cfg['excellence'] ? 'excellence' : ($p->has_test ? 'competency' : 'completion');
                    $this->issue(['employee_id' => $en->employee_id, 'type' => $type, 'title' => $s->title, 'session_id' => $s->id]);
                    foreach ($cfg['impact_points'] as $pt) TdImpact::firstOrCreate(['enrollment_id' => $en->id, 'point' => (int) $pt], ['kind' => (int) $pt === (int) $cfg['impact_form_point'] ? 'behavior' : ((int) $pt >= 90 ? 'kpi' : 'talk'),
                        'due_on' => $s->ends_on->copy()->addDays((int) $pt)->toDateString(), 'manager_id' => Employee::whereKey($en->employee_id)->value('manager_id'), 'status' => 'pending']);
                    if ($uid = User::where('employee_id', $en->employee_id)->value('id')) Notify::user($uid, "أكملت «{$s->title}» — اكتب خطة التطبيق: ثلاثة أشياء ستطبقها", "You completed “{$s->title}” — write your application plan: three things you will apply", 'training');
                }
                if ($en->need_id && $res === 'passed') TdNeed::whereKey($en->need_id)->update(['status' => 'done']);
            }
            $s->update(['status' => 'completed']);
            if ($s->trainer_id) TdTrainer::whereKey($s->trainer_id)->increment('hours_given', (float) $p->hours);
        });
        if (\App\Services\Missions\Missions::cfg()['trainer_fee_auto'] ?? false) \App\Services\Missions\MissionExtras::trainerFee($s);   // FR-TM-021
        return $s->fresh();
    }

    /** BR-07: غير الناجح ← قرار موثق: إعادة، تمديد، أو تحميل النفقات وفق التعهد (لا خصم آلي دون اعتماد) */
    public function failureDecision(TdEnrollment $en, string $decision, string $note, User $u): TdEnrollment
    {
        if (! Td::manages($u) || ! $u->hasRole('hr_director', 'admin')) abort(403);
        if ($en->result !== 'failed') throw new ApiException(L::t('المتدرب ليس «غير ناجح».', 'The trainee did not fail.'), 'invalid', 409);
        if (! in_array($decision, ['retrain', 'extend', 'charge'], true) || mb_strlen(trim($note)) < self::min()) throw ApiException::field('note', L::t('اختر القرار ووثّق سببه.', 'Choose the decision and document it.'), 'reason_required');
        $en->update(['failure' => ['decision' => $decision, 'note' => $note, 'by' => $u->displayName('ar'), 'at' => now()->toIso8601String()]]);
        if ($decision === 'charge' && ($c = TdCommitment::where('enrollment_id', $en->id)->whereIn('status', ['active', 'generated'])->first()))
            $c->update(['status' => 'breached', 'recovery' => $c->cost, 'decision_note' => $note]);
        if ($decision === 'retrain') TdNeed::create(['ref' => 'TN-'.now()->year.'-'.str_pad((string) Counters::next('td-need-'.now()->year), 4, '0', STR_PAD_LEFT), 'employee_id' => $en->employee_id, 'title' => 'إعادة: '.TdSession::whereKey($en->session_id)->value('title'),
            'path' => 'training', 'source' => 'event', 'source_ref' => 'enrollment:'.$en->id, 'status' => 'approved', 'cause' => 'knowledge', 'evidence' => ['appraisal', 'observation'], 'priority' => 'high', 'score' => 50]);
        Audit::record('td_enrollment.failure', $en, ['decision' => $decision]);
        return $en;
    }

    /** BR-16: لا يُغلق التنفيذ قبل اعتماد الحضور وإدخال النتائج ووصول الاستبيان للحد الأدنى */
    public function close(TdSession $s, array $actual, User $u): TdSession
    {
        if (! Td::manages($u)) abort(403);
        if ($s->status !== 'completed') throw new ApiException(L::t('أكمل التنفيذ أولاً.', 'Complete the run first.'), 'invalid', 409);
        $ens = TdEnrollment::where('session_id', $s->id)->where('status', 'completed')->get();
        $missing = $ens->filter(fn ($e) => count($e->attendance ?? []) < count($s->meetings))->count();
        $p = TdProgram::find($s->program_id);
        $noScore = $p->has_test ? $ens->whereNull('post_score')->count() : 0;
        $resp = TdSurvey::where('session_id', $s->id)->count();
        $need = (int) ceil($ens->count() * (int) Td::cfg()['survey_min'] / 100);
        $errs = [];
        if ($missing) $errs[] = L::t("حضور غير مكتمل لـ {$missing} متدرب", "Attendance incomplete for {$missing} trainee(s)");
        if ($noScore) $errs[] = L::t("نتائج الاختبار ناقصة لـ {$noScore} متدرب", "Test scores missing for {$noScore} trainee(s)");
        if ($resp < $need) $errs[] = L::t("استجابة الاستبيان {$resp} من {$need} مطلوبة", "Survey responses {$resp} of {$need} required");
        if ($errs) throw new ApiException(implode(' · ', $errs), 'close_blocked', 409);
        if ($actual) $s->update(['costs' => $actual]);
        $s->update(['status' => 'closed', 'closed_at' => now()]);
        if ($s->plan_item_id) {
            $item = TdPlanItem::find($s->plan_item_id);
            $cost = (float) TdSession::where('plan_item_id', $item->id)->where('status', 'closed')->get()->sum(fn ($x) => self::totalCost($x));
            $item->update(['actual_cost' => $cost, 'status' => TdSession::where('plan_item_id', $item->id)->whereNotIn('status', ['closed', 'cancelled'])->exists() ? 'in_progress' : 'done']);
        }
        Notify::hr("أُغلق التنفيذ «{$s->title}» — التكلفة الفعلية ".number_format(self::totalCost($s)).' '.$s->currency, "Run “{$s->title}” closed — actual cost ".number_format(self::totalCost($s)).' '.$s->currency, 'training:sessions');
        return $s->fresh();
    }

    public function cancel(TdSession $s, string $reason, User $u): TdSession
    {
        if (! Td::manages($u)) abort(403);
        if (in_array($s->status, ['completed', 'closed'], true)) throw new ApiException(L::t('لا يُلغى تنفيذ مكتمل.', 'A completed run cannot be cancelled.'), 'invalid', 409);
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('اكتب السبب.', 'Write the reason.'), 'reason_required');
        foreach (TdEnrollment::where('session_id', $s->id)->whereNotIn('status', ['withdrawn'])->get() as $en) {
            $this->markDays($en, false);
            $en->update(['status' => 'withdrawn', 'result' => 'withdrawn', 'result_reason' => 'cancelled']);
            if ($uid = User::where('employee_id', $en->employee_id)->value('id')) Notify::user($uid, "أُلغيت دورة «{$s->title}»", "“{$s->title}” was cancelled", 'training');
        }
        TdCommitment::whereIn('enrollment_id', TdEnrollment::where('session_id', $s->id)->pluck('id'))->whereIn('status', ['generated', 'active'])->update(['status' => 'cancelled']);
        $s->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
        return $s;
    }

    // ———————————————— التقييم ————————————————

    /** F-07 برابط أو QR دون حساب، مع إخفاء الهوية اختيارياً */
    public function survey(string $token, array $ratings, array $answers, ?User $u, bool $anonymous): TdSurvey
    {
        $s = TdSession::where('survey_token', $token)->firstOrFail();
        if (! in_array($s->status, ['in_progress', 'completed'], true)) throw new ApiException(L::t('الاستبيان غير متاح الآن.', 'The survey is not open now.'), 'closed', 409);
        $n = count(Td::cfg()['survey_items']);
        $vals = array_slice(array_map(fn ($v) => max(1, min(5, (int) $v)), array_values($ratings)), 0, $n);
        if (count($vals) < $n) throw ApiException::field('ratings', L::t('قيّم كل البنود.', 'Rate every item.'), 'required');
        $en = $u?->employee_id ? TdEnrollment::where('session_id', $s->id)->where('employee_id', $u->employee_id)->first() : null;
        if ($en && TdSurvey::where('enrollment_id', $en->id)->exists()) throw new ApiException(L::t('أرسلت رأيك من قبل.', 'You already answered.'), 'answered', 409);
        return TdSurvey::create(['company_id' => $s->company_id, 'session_id' => $s->id, 'enrollment_id' => $anonymous ? null : $en?->id, 'ratings' => $vals, 'answers' => $answers, 'avg' => round(array_sum($vals) / count($vals), 2)]);
    }

    /** F-13 + BR-03: تقييم المدرب والمركز، ومؤشر الجودة، وتوصية الحظر */
    public function rate(TdSession $s, array $scores, ?string $note, User $u): TdRating
    {
        if (! Td::manages($u)) abort(403);
        $keys = ['content', 'trainer', 'punctuality', 'facilities', 'results', 'value'];
        $sc = array_map(fn ($k) => max(1, min(5, (int) ($scores[$k] ?? 0))), array_combine($keys, $keys));
        $avg = round(array_sum($sc) / count($sc), 2);
        $results = TdEnrollment::where('session_id', $s->id)->whereNotNull('post_score')->avg('post_score');
        $ban = $avg < 2.5 || ($results !== null && (float) $results < (float) Td::cfg()['ban_threshold']);
        $r = TdRating::updateOrCreate(['session_id' => $s->id], ['scores' => $sc, 'avg' => $avg, 'recommendation' => $ban ? 'ban' : ($avg < 3.5 ? 'watch' : 'approve'), 'note' => $note, 'rated_by' => $u->id]);
        foreach ([['trainer_id', TdTrainer::class, 'rating'], ['center_id', TdCenter::class, 'quality']] as [$k, $cls, $col]) if ($s->$k) {
            $ids = TdSession::where($k, $s->$k)->pluck('id');
            $all = TdRating::whereIn('session_id', $ids);
            $cls::whereKey($s->$k)->update([$col => round((float) $all->avg('avg'), 2), 'ratings' => $all->count()]);
        }
        if ($ban) Notify::hr("توصية حظر للجهة المدربة في «{$s->title}» — تقييم {$avg}".($results !== null ? ' ونتائج '.round($results) : ''), "Ban recommended for the provider of “{$s->title}” — rating {$avg}", 'training:providers');
        return $r;
    }

    /** F-12: ثلاثة أشياء سأطبقها ومتى ← مهام في موديول المهام */
    public function applyPlan(TdEnrollment $en, array $items, ?string $support, User $u): TdEnrollment
    {
        if ($u->employee_id !== $en->employee_id) abort(403);
        if ($en->result !== 'passed') throw new ApiException(L::t('خطة التطبيق بعد إتمام الدورة بنجاح.', 'The application plan follows a successful completion.'), 'invalid', 409);
        if ($en->apply_plan) throw new ApiException(L::t('أُرسلت الخطة من قبل.', 'Already sent.'), 'sent', 409);
        $items = array_values(array_filter($items, fn ($i) => mb_strlen(trim((string) ($i['what'] ?? ''))) >= 3));
        if (count($items) < 1 || count($items) > 3) throw ApiException::field('items', L::t('اكتب من شيء إلى ثلاثة أشياء ستطبقها.', 'Write one to three things you will apply.'), 'invalid');
        $s = TdSession::find($en->session_id);
        foreach ($items as $i) WorkTask::create(['title' => 'تطبيق: '.$i['what'], 'description' => 'من خطة التطبيق لدورة «'.$s->title.'»', 'employee_id' => $en->employee_id, 'created_by' => $u->id,
            'priority' => 'normal', 'status' => 'todo', 'progress' => 0, 'due_on' => $i['when'] ?? now()->addDays(30)->toDateString()]);
        $en->update(['apply_plan' => ['items' => $items, 'support' => $support, 'at' => now()->toIso8601String()]]);
        return $en;
    }

    /** F-05 / متابعة 30-60-90: المدير المباشر يقيّم؛ نجاح قياس السلوك يحدّث مستوى الكفاءة (مصدر «تدريب + تحقق المدير») */
    public function impact(TdImpact $i, array $v, User $u): TdImpact
    {
        $en = TdEnrollment::with('session')->find($i->enrollment_id); $e = Employee::find($en->employee_id);
        if (! Td::manages($u) && ($u->employee_id === null || $u->employee_id !== ($i->manager_id ?? $e->manager_id))) abort(403);
        if ($i->status === 'done') throw new ApiException(L::t('سُجّل هذا القياس.', 'Already recorded.'), 'done', 409);
        $avg = null; $ratings = null;
        if ($i->kind === 'behavior') {
            $p = TdProgram::find($en->session->program_id); $items = $p->impact_items ?: Td::cfg()['impact_items'];
            $ratings = array_slice(array_map(fn ($x) => in_array((int) $x, [20, 40, 60, 80, 100], true) ? (int) $x : 0, array_values($v['ratings'] ?? [])), 0, count($items));
            if (count(array_filter($ratings)) < count($items)) throw ApiException::field('ratings', L::t('قيّم كل البنود (20%–100%).', 'Rate every item (20%–100%).'), 'required');
            $avg = round(array_sum($ratings) / count($ratings), 1);
        } elseif ($i->kind === 'kpi') {
            $avg = isset($v['after'], $v['before']) && (float) $v['before'] != 0 ? round(((float) $v['after'] - (float) $v['before']) * 100 / abs((float) $v['before']), 1) : null;
            $ratings = ['before' => $v['before'] ?? null, 'after' => $v['after'] ?? null];
        }
        $i->update(['ratings' => $ratings, 'notes' => $v['notes'] ?? null, 'barriers' => $v['barriers'] ?? null, 'avg' => $avg, 'status' => 'done', 'done_at' => now()]);
        if ($i->kind === 'behavior' && $avg !== null && $avg >= (float) Td::cfg()['impact_pass']) {
            $p = TdProgram::find($en->session->program_id);
            foreach ($p->competencies ?? [] as $c) if (! empty($c['id'])) {
                $cur = Td::current($e)[$c['id']] ?? 0;
                if ((int) ($c['level'] ?? 0) > $cur) Td::setLevel($e, (int) $c['id'], (int) $c['level'], 'training', 'session:'.$en->session_id);
            }
        }
        return $i;
    }

    // ———————————————— الشهادات ————————————————

    public function issue(array $v): TdCertificate
    {
        return TdCertificate::create($v + ['no' => 'TC-'.now()->year.'-'.str_pad((string) Counters::next('td-cert-'.now()->year), 5, '0', STR_PAD_LEFT), 'code' => strtoupper(Str::random(10)), 'issued_on' => now()->toDateString(), 'source' => 'internal', 'status' => 'valid']);
    }

    /** FR-TD-102: شهادة خارجية مرفوعة ← «بانتظار التحقق» ثم اعتماد أو رفض */
    public function upload(Employee $e, array $v, User $u): TdCertificate
    {
        if ($u->employee_id !== $e->id && ! Td::manages($u)) abort(403);
        return TdCertificate::create(['employee_id' => $e->id, 'type' => 'professional', 'title' => $v['title'], 'issuer' => $v['issuer'] ?? null, 'competency_id' => $v['competency_id'] ?? null,
            'issued_on' => $v['issued_on'], 'valid_to' => $v['valid_to'] ?? null, 'source' => 'external', 'file_id' => $v['file_id'], 'status' => Td::manages($u) ? 'valid' : 'pending', 'verified_by' => Td::manages($u) ? $u->id : null]);
    }

    public function verifyCert(TdCertificate $c, bool $ok, ?string $note, User $u): TdCertificate
    {
        if (! Td::manages($u)) abort(403);
        if ($c->status !== 'pending') throw new ApiException(L::t('روجعت الشهادة.', 'Already reviewed.'), 'invalid', 409);
        $c->update(['status' => $ok ? 'valid' : 'rejected', 'status_note' => $note, 'verified_by' => $u->id]);
        if ($ok && $c->competency_id && $c->employee_id) Td::setLevel(Employee::find($c->employee_id), $c->competency_id, max(Td::current(Employee::find($c->employee_id))[$c->competency_id] ?? 0, 3), 'training', 'certificate:'.$c->id);
        return $c;
    }

    public function revoke(TdCertificate $c, string $reason, User $u): TdCertificate
    {
        if (! Td::manages($u)) abort(403);
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('اكتب السبب.', 'Write the reason.'), 'reason_required');
        $c->update(['status' => 'revoked', 'status_note' => $reason]);
        Audit::record('td_certificate.revoked', $c);
        return $c;
    }

    public static function verify(string $code): ?array
    {
        $c = TdCertificate::allCompanies()->where('code', strtoupper(trim($code)))->first();
        if (! $c) return null;
        $name = $c->employee_id ? Employee::allCompanies()->find($c->employee_id) : null; $x = $c->external_id ? \App\Models\External::allCompanies()->find($c->external_id) : null;
        $co = \App\Models\Company::find($c->company_id);
        $state = $c->status === 'valid' && $c->valid_to && $c->valid_to->isPast() ? 'expired' : $c->status;
        return ['no' => $c->no, 'type' => $c->type, 'title' => $c->title, 'name' => ['ar' => $name?->name ?? $x?->name ?? '—', 'en' => $name?->name_en ?? $x?->name_en ?? $x?->name ?? '—'],
            'issued_on' => $c->issued_on->toDateString(), 'valid_to' => $c->valid_to?->toDateString(), 'status' => $state, 'company' => ['ar' => $co?->name_ar, 'en' => $co?->name_en]];
    }

    // ———————————————— المتابعة الدورية ————————————————

    /** BR-17 + FR-TD-103 + انتهاء التعهدات: تذكير F-05 ثم التصعيد، تنبيهات صلاحية الشهادات واحتياج التجديد، والوفاء بالتعهد */
    public function sweep(): int
    {
        $cfg = Td::cfg(); $n = 0;
        foreach (TdImpact::with('enrollment.session')->where('status', 'pending')->where('due_on', '<=', now()->subDays((int) $cfg['remind_days'])->toDateString())->get() as $i) {
            $mgr = $i->manager_id ? Employee::find($i->manager_id) : null;
            $title = $i->enrollment?->session?->title ?? '';
            if (! $i->reminded_at && $mgr && ($uid = User::where('employee_id', $mgr->id)->value('id'))) {
                Notify::user($uid, "متابعة أثر التدريب ({$i->point} يوماً) لدورة «{$title}» بانتظارك", "Training follow-up ({$i->point} days) for “{$title}” awaits you", 'training-team');
                $i->update(['reminded_at' => now()]); $n++;
            } elseif (! $i->escalated_at && $i->due_on->lte(now()->subDays((int) $cfg['escalate_days']))) {
                $up = $mgr?->manager_id ? User::where('employee_id', $mgr->manager_id)->value('id') : null;
                $up ? Notify::user($up, "تأخرت متابعة أثر التدريب لـ «{$title}» لدى {$mgr->name}", "Training follow-up for “{$title}” is overdue with {$mgr->name_en}", 'training-team') : Notify::hr("تأخرت متابعة أثر التدريب لـ «{$title}»", "Training follow-up for “{$title}” is overdue", 'training');
                $i->update(['escalated_at' => now()]); $n++;
            }
        }
        foreach (TdCertificate::where('status', 'valid')->whereNotNull('valid_to')->whereNotNull('employee_id')->get() as $c) {
            $days = now()->startOfDay()->diffInDays($c->valid_to, false);
            if ($days < 0) { $c->update(['status' => 'expired']); continue; }
            $th = collect($cfg['cert_alerts'])->map(fn ($x) => (int) $x)->sort()->values();
            $d = $th->first(fn ($x) => $days <= $x);   // أقرب نقطة تنبيه بلغناها
            if ($d === null || in_array($d, $c->alerts ?? [], true)) continue;
            $c->update(['alerts' => array_values(array_unique(array_merge($c->alerts ?? [], $th->filter(fn ($x) => $x >= $d)->all())))]);
            if ($uid = User::where('employee_id', $c->employee_id)->value('id')) Notify::user($uid, "تنتهي شهادة «{$c->title}» خلال {$days} يوماً", "Certificate “{$c->title}” expires in {$days} days", 'training');
            if ($d === $th->first() && ! TdNeed::where('employee_id', $c->employee_id)->where('source_ref', 'certificate:'.$c->id)->exists())
                TdNeed::create(['ref' => 'TN-'.now()->year.'-'.str_pad((string) Counters::next('td-need-'.now()->year), 4, '0', STR_PAD_LEFT), 'employee_id' => $c->employee_id, 'competency_id' => $c->competency_id, 'title' => 'تجديد: '.$c->title,
                    'path' => 'training', 'source' => 'event', 'source_ref' => 'certificate:'.$c->id, 'status' => 'suggested', 'priority' => 'high', 'score' => 60]);
            $n++;
        }
        $n += TdCommitment::where('status', 'active')->whereNotNull('ends_on')->where('ends_on', '<', now()->toDateString())->update(['status' => 'fulfilled']);
        return $n;
    }
}
