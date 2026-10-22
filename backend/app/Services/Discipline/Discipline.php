<?php

namespace App\Services\Discipline;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MonthSheet;
use App\Models\PenaltyApproval;
use App\Models\User;
use App\Models\ViolationType;
use App\Services\Attendance\Attendance;
use App\Services\Attendance\DayCalc;
use App\Services\People\Directory;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Signature;
use App\Support\ViolationCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M17 المخالفات والجزاءات (SRS §10.7، المادة 98 من قانون العمل) — كل الحدود من سياسة discipline:
 * - فتح حالة (HR، أو المدير لفريقه، أو اقتراح آلي من الحضور) ← إبلاغ الموظف كتابة ← أقواله من بوابته + محضر التحقيق ← القرار.
 * - الدرجة (أول/ثاني/…) من سجل الموظف لنفس المخالفة خلال repeat_months، والجزاء المقترح منها؛ التخفيف بسبب، ولا تشديد فوق الدرجة.
 * - لا قرار قبل محضر التحقيق، ولا بعد deadline_days من الاكتشاف (تنقضي الحالة تلقائياً).
 * - الصلاحية: تنبيه/إنذار/حسم حتى hr_max_days للموارد البشرية، وما فوقه (الترفيع، الفصل) بموافقة المدير العام (سلسلة penalty).
 * - مجموع الحسم بالشهر لا يتجاوز cap_days؛ الحسم يظهر في الكشف الشهري، والفصل يُنبّه الموارد البشرية لفتح إنهاء العقد F-03.
 */
class Discipline
{
    /** شدة الجزاء للمقارنة (التخفيف فقط) */
    public static function rank(array $p): float
    {
        return match ($p['kind']) {
            'note' => 1, 'warning' => 2, 'absent', 'fine' => 2.5, 'deduct' => 3 + min(10, (float) ($p['days'] ?? 0)) / 10,
            'promotion_delay' => 5, 'promotion_deny' => 6, 'dismissal' => 7, default => 0,
        };
    }

    /** هل يحتاج الجزاء موافقة المدير العام؟ */
    public static function needsGm(array $p, ?string $on = null): bool
    {
        if (in_array($p['kind'], ['promotion_delay', 'promotion_deny', 'dismissal'], true)) return true;
        return $p['kind'] === 'deduct' && (float) $p['days'] > (float) Settings::policy('discipline', $on)['hr_max_days'];
    }

    /** الدرجة التالية للموظف في هذه المخالفة والجزاء المقترح منها */
    public static function grade(Employee $e, ViolationType $t, string $on, ?int $except = null): array
    {
        $months = (int) Settings::policy('discipline', $on)['repeat_months'];
        $prev = DisciplineCase::where('employee_id', $e->id)->where('violation_type_id', $t->id)->where('status', 'decided')
            ->where('occurred_on', '>=', Carbon::parse($on)->subMonths($months)->toDateString())->where('occurred_on', '<=', $on)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->count();
        $occ = $prev + 1;
        $pens = $t->penalties ?: [['kind' => 'warning']];
        return ['occurrence' => $occ, 'penalty' => $pens[min($occ, count($pens)) - 1]];
    }

    public function open(User $by, Employee $e, array $in, string $source = 'manual'): DisciplineCase
    {
        $isManager = $by->employee_id && $e->manager_id === $by->employee_id;
        if (! Perm::can($by, 'discipline', 'create') || (! $isManager && Perm::scope($by, 'discipline') !== 'all')) {
            throw new ApiException(L::t('فتح حالة مخالفة من الموارد البشرية أو المدير المباشر لفريقه.', 'A case is opened by HR or by the direct manager for their team.'), 'forbidden', 403);
        }
        if ($e->id === $by->employee_id) throw new ApiException(L::t('لا يمكنك فتح حالة بحق نفسك.', 'You cannot open a case against yourself.'), 'forbidden', 403);
        $t = ViolationType::where('active', true)->find($in['violation_type_id'] ?? 0) ?? throw ApiException::field('violation_type_id', L::t('اختر المخالفة من الدليل.', 'Choose the violation from the catalogue.'), 'unknown');
        $occurred = $in['occurred_on'];
        $today = now()->toDateString();
        if ($occurred > $today) throw ApiException::field('occurred_on', L::t('تاريخ المخالفة لا يكون في المستقبل.', 'The violation date cannot be in the future.'), 'future');
        $p = Settings::policy('discipline');
        $g = self::grade($e, $t, $occurred);
        return DB::transaction(function () use ($by, $e, $t, $in, $occurred, $today, $p, $g, $source) {
            $c = DisciplineCase::create(['ref_no' => 'DSC-'.now()->year.'-'.str_pad((string) Counters::next('discipline-'.now()->year), 4, '0', STR_PAD_LEFT),
                'employee_id' => $e->id, 'violation_type_id' => $t->id, 'occurred_on' => $occurred, 'discovered_on' => $today,
                'deadline' => Carbon::parse($today)->addDays((int) $p['deadline_days'])->toDateString(), 'source' => $source,
                'description' => trim($in['description']), 'status' => 'open', 'occurrence' => $g['occurrence'], 'suggested' => $g['penalty'], 'opened_by' => $by->id]);
            $e->log("فتح حالة مخالفة {$c->ref_no}: {$t->title_ar} (المرة {$g['occurrence']})", "Disciplinary case {$c->ref_no}: {$t->title_en} (occurrence {$g['occurrence']})", 'warn');
            // الإبلاغ الكتابي للموظف — يكتب أقواله من بوابته
            Notify::user(User::where('employee_id', $e->id)->value('id'), "إبلاغ بمخالفة {$c->ref_no}: {$t->title_ar} — يمكنك كتابة أقوالك ودفاعك قبل {$c->deadline->toDateString()}",
                "Notice of violation {$c->ref_no}: {$t->title_en} — you may submit your statement before {$c->deadline->toDateString()}", 'discipline');
            if (Perm::scope($by, 'discipline') !== 'all') Notify::hr("حالة مخالفة جديدة {$c->ref_no} فتحها المدير {$by->displayName('ar')}", "New disciplinary case {$c->ref_no} opened by manager {$by->displayName('en')}", 'discipline:'.$c->id);
            return $c;
        });
    }

    /** أقوال الموظف ودفاعه من بوابته (قبل القرار) */
    public function defend(DisciplineCase $c, User $u, string $text): DisciplineCase
    {
        if ($u->employee_id !== $c->employee_id) abort(404);
        if (! in_array($c->status, ['open', 'investigated'], true)) throw new ApiException(L::t('انتهت مرحلة سماع الأقوال لهذه الحالة.', 'Statements are closed for this case.'), 'case_closed', 409);
        $c->update(['defense' => trim($text), 'defended_at' => now()]);
        $c->employee->log("قدّم الموظف أقواله في الحالة {$c->ref_no}", "Statement submitted for case {$c->ref_no}");
        Notify::hr("أقوال الموظف في الحالة {$c->ref_no} وصلت", "Employee statement received for case {$c->ref_no}", 'discipline:'.$c->id);
        return $c;
    }

    /** محضر التحقيق: أقواله ودفاعه والشهود، وإبلاغ التنظيم النقابي */
    public function investigate(DisciplineCase $c, User $u, array $in): DisciplineCase
    {
        if (! in_array($c->status, ['open', 'investigated'], true)) throw new ApiException(L::t('الحالة ليست في مرحلة التحقيق.', 'The case is not under investigation.'), 'case_closed', 409);
        $c->update(['status' => 'investigated', 'investigation' => ['statement' => trim($in['statement']), 'witnesses' => trim((string) ($in['witnesses'] ?? '')) ?: null,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'union_notified' => (bool) ($in['union_notified'] ?? false), 'at' => now()->toIso8601String(), 'by' => $u->displayName('ar'), 'by_en' => $u->displayName('en')]]);
        $c->employee->log("محضر تحقيق في الحالة {$c->ref_no} ({$u->displayName('ar')})", "Investigation record for case {$c->ref_no} ({$u->displayName('en')})", 'info', 'F-01');
        return $c;
    }

    /** القرار: الجزاء المقترح أو أخف منه بسبب. الأشد من صلاحية HR ← طلب موافقة المدير العام */
    public function decide(DisciplineCase $c, User $u, array $penalty, ?string $reason): DisciplineCase
    {
        $c->loadMissing('type', 'employee');
        if ($c->status === 'pending_gm') throw new ApiException(L::t('القرار بانتظار موافقة المدير العام.', 'The decision awaits the general manager.'), 'pending_gm', 409);
        if ($c->status !== 'investigated') throw new ApiException(L::t('لا جزاء قبل سماع أقوال العامل — سجّل محضر التحقيق أولاً.', 'No penalty before hearing the employee — record the investigation first.'), 'investigation_required', 409);
        if (now()->toDateString() > $c->deadline->toDateString()) throw new ApiException(L::t('انقضت مهلة القرار من تاريخ اكتشاف المخالفة.', 'The decision period from discovery has passed.'), 'deadline_passed', 409);
        if (! isset(ViolationCatalog::KINDS[$penalty['kind'] ?? ''])) throw ApiException::field('kind', L::t('اختر الجزاء.', 'Choose the penalty.'), 'unknown');
        $penalty = $penalty['kind'] === 'deduct' ? ['kind' => 'deduct', 'days' => round((float) ($penalty['days'] ?? 0), 2)] : ['kind' => $penalty['kind']];
        if ($penalty['kind'] === 'deduct' && ($penalty['days'] <= 0 || $penalty['days'] > 30)) throw ApiException::field('days', L::t('أيام الحسم غير صحيحة.', 'Invalid deduction days.'), 'invalid');
        // الدرجة تُعاد حسابها عند القرار (قد تُحسم حالة سابقة بينهما)
        $g = self::grade($c->employee, $c->type, $c->occurred_on->toDateString(), $c->id);
        $c->update(['occurrence' => $g['occurrence'], 'suggested' => $g['penalty']]);
        $sug = self::rank($g['penalty']);
        $rank = self::rank($penalty);
        if ($rank > $sug + 0.0001) throw ApiException::field('kind', L::t('لا يتجاوز الجزاء درجة المخالفة ('.ViolationCatalog::label($g['penalty']).').', 'The penalty cannot exceed the violation’s grade ('.ViolationCatalog::label($g['penalty'], 'en').').'), 'over_grade');
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        $reason = trim((string) $reason) ?: null;
        if ($rank < $sug - 0.0001 && mb_strlen((string) $reason) < $min) throw ApiException::field('reason', L::t("سبب التخفيف إلزامي ({$min} أحرف على الأقل).", "A reason for the lighter penalty is required (at least {$min} characters)."), 'reason_required');

        return DB::transaction(function () use ($c, $u, $penalty, $reason) {
            if (self::needsGm($penalty)) {
                $pa = PenaltyApproval::create(['discipline_case_id' => $c->id, 'employee_id' => $c->employee_id, 'penalty' => $penalty, 'reason' => $reason, 'status' => 'pending_exec', 'requested_by' => $u->id]);
                $c->update(['status' => 'pending_gm', 'penalty' => $penalty, 'reduce_reason' => $reason]);
                $c->employee->log("طلب موافقة المدير العام على جزاء الحالة {$c->ref_no}: ".ViolationCatalog::label($penalty), "General manager approval requested for case {$c->ref_no}: ".ViolationCatalog::label($penalty, 'en'), 'warn');
                app(Workflow::class)->start($pa, $c->employee, Workflow::chain('penalty'), $u);
                return $c->refresh();
            }
            return $this->apply($c, $u, $penalty, $reason);
        });
    }

    /** قرار المدير العام (من صندوق الاعتماد) */
    public function decideApproval(PenaltyApproval $pa, User $u, bool $approve, ?string $note): PenaltyApproval
    {
        $pa = app(Workflow::class)->decide($pa, $u, $approve, $note, false, function (PenaltyApproval $x) use ($u) {
            $c = DisciplineCase::with('type', 'employee')->findOrFail($x->discipline_case_id);
            $this->apply($c, $u, $x->penalty, $x->reason);
        });
        if (! $approve) {
            $c = DisciplineCase::with('employee')->findOrFail($pa->discipline_case_id);
            $c->update(['status' => 'investigated', 'penalty' => null]);
            Notify::hr("رفض المدير العام جزاء الحالة {$c->ref_no}: {$note} — قرّر جزاءً ضمن صلاحيتك أو احفظ الحالة", "The general manager declined the penalty for {$c->ref_no}: {$note} — decide within your authority or close the case", 'discipline:'.$c->id);
        }
        return $pa;
    }

    /** تنفيذ الجزاء: سقف الحسم بالشهر، الإبلاغ للإقرار، الأثر في الملف */
    private function apply(DisciplineCase $c, User $u, array $penalty, ?string $reason): DisciplineCase
    {
        $e = $c->employee;
        $upd = ['status' => 'decided', 'penalty' => $penalty, 'reduce_reason' => $reason, 'decided_by' => $u->id, 'decided_at' => now(), 'deduct_days' => 0, 'capped' => false, 'month' => null];
        if ($penalty['kind'] === 'deduct') {
            $month = now()->format('Y-m');
            if (MonthSheet::where('month', $month)->where('status', 'closed')->exists()) $month = now()->addMonthNoOverflow()->format('Y-m');
            $cap = (float) Settings::policy('discipline')['cap_days'];
            $used = (float) DisciplineCase::where('employee_id', $e->id)->where('month', $month)->where('status', 'decided')->where('id', '!=', $c->id)->sum('deduct_days');
            $days = max(0, min((float) $penalty['days'], $cap - $used));
            $upd = ['deduct_days' => $days, 'capped' => $days < (float) $penalty['days'], 'month' => $month] + $upd;
        }
        $c->update($upd);
        $label = [ViolationCatalog::label($penalty), ViolationCatalog::label($penalty, 'en')];
        $e->log("جزاء الحالة {$c->ref_no}: {$label[0]}".($c->capped ? " (بسقف الشهر: {$c->deduct_days} يوم)" : ''), "Penalty for case {$c->ref_no}: {$label[1]}".($c->capped ? " (monthly cap: {$c->deduct_days} day(s))" : ''),
            in_array($penalty['kind'], ['note', 'warning'], true) ? 'warn' : 'danger', $penalty['kind'] === 'warning' ? 'F-01' : null);
        Notify::user(User::where('employee_id', $e->id)->value('id'), "قرار في الحالة {$c->ref_no}: {$label[0]} — اطّلع عليه ووقّع بالاستلام", "Decision on case {$c->ref_no}: {$label[1]} — read it and sign the acknowledgement", 'discipline');
        if ($penalty['kind'] === 'dismissal') Notify::hr("قرار فصل في الحالة {$c->ref_no} — افتح إنهاء العقد F-03 لـ {$e->name}", "Dismissal decided in case {$c->ref_no} — open contract termination F-03 for {$e->name_en}", 'emp:'.$e->id);
        Audit::record('discipline.decided', $c, null, ['penalty' => $penalty, 'deduct_days' => $c->deduct_days, 'capped' => $c->capped, 'reason' => $reason]);
        return $c->refresh();
    }

    /** حفظ الحالة بلا جزاء (بسبب) */
    public function dismiss(DisciplineCase $c, User $u, string $reason): DisciplineCase
    {
        if (! in_array($c->status, ['open', 'investigated'], true)) throw new ApiException(L::t('لا يمكن حفظ هذه الحالة الآن.', 'This case cannot be closed now.'), 'case_closed', 409);
        $c->update(['status' => 'dismissed', 'close_reason' => trim($reason), 'decided_by' => $u->id, 'decided_at' => now()]);
        $c->employee->log("حفظ الحالة {$c->ref_no} بلا جزاء: {$reason}", "Case {$c->ref_no} closed without penalty: {$reason}", 'ok');
        Notify::user(User::where('employee_id', $c->employee_id)->value('id'), "حُفظت الحالة {$c->ref_no} بلا جزاء", "Case {$c->ref_no} was closed without a penalty", 'discipline');
        return $c;
    }

    /** إقرار الموظف باستلام القرار بتوقيعه */
    public function acknowledge(DisciplineCase $c, User $u, ?string $signature): DisciplineCase
    {
        if ($u->employee_id !== $c->employee_id) abort(404);
        if ($c->status !== 'decided') throw new ApiException(L::t('لا قرار للإقرار به.', 'There is no decision to acknowledge.'), 'not_decided', 409);
        if ($c->acknowledged_at) throw new ApiException(L::t('سبق الإقرار.', 'Already acknowledged.'), 'already_signed', 409);
        $f = Signature::store($signature);
        $c->update(['acknowledged_at' => now(), 'ack_signature_file_id' => $f->id]);
        $c->employee->log("إقرار باستلام قرار الحالة {$c->ref_no}", "Acknowledged the decision on case {$c->ref_no}", 'ok', ($c->penalty['kind'] ?? '') === 'warning' ? 'F-01' : null);
        return $c;
    }

    /** انقضاء المهلة: لا جزاء بعد deadline_days من الاكتشاف (يومياً) */
    public function expire(): int
    {
        $n = 0;
        DisciplineCase::with('employee')->whereIn('status', ['open', 'investigated'])->whereDate('deadline', '<', now()->toDateString())->get()->each(function (DisciplineCase $c) use (&$n) {
            $c->update(['status' => 'expired', 'close_reason' => 'deadline']);
            $c->employee->log("انقضت مهلة القرار في الحالة {$c->ref_no}", "Decision period expired for case {$c->ref_no}", 'warn');
            Notify::hr("انقضت مهلة القرار في الحالة {$c->ref_no} دون جزاء", "Case {$c->ref_no} expired without a decision", 'discipline:'.$c->id);
            $n++;
        });
        return $n;
    }

    /**
     * الاقتراح الآلي من الحضور لشهر (حتى أمس): تأخر غير مبرر suggest_late مرة فأكثر (البند حسب أطول تأخير)، غياب غير مبرر suggest_absent يوم،
     * خروج مبكر suggest_early مرة. يستثنى من له حالة من الحضور لنفس البند في نفس الشهر.
     */
    public function suggestions(User $u, ?string $month = null): array
    {
        $month ??= now()->format('Y-m');
        $from = $month.'-01';
        $to = min(Carbon::parse($from)->endOfMonth()->toDateString(), now()->subDay()->toDateString());
        $p = Settings::policy('discipline', $to);
        $start = max($from, Settings::policy('payroll', $to)['start_date'] ?? $from);
        if ($start > $to) return [];
        $types = ViolationType::where('active', true)->whereNotNull('auto')->get()->keyBy('auto');
        $emps = Directory::scoped($u, 'discipline')->current()->get()->keyBy('id');
        $days = AttendanceDay::whereIn('employee_id', $emps->keys())->whereBetween('date', [$start, $to])->get()->groupBy('employee_id');
        $leave = Attendance::onLeave($emps->keys(), $start, $to);
        $existing = DisciplineCase::whereIn('employee_id', $emps->keys())->where('source', 'attendance')->whereBetween('occurred_on', [$from, Carbon::parse($from)->endOfMonth()->toDateString()])
            ->whereNotIn('status', ['dismissed', 'expired'])->get(['employee_id', 'violation_type_id'])->map(fn ($c) => $c->employee_id.':'.$c->violation_type_id)->flip();
        $out = [];
        foreach ($emps as $e) {
            $rows = ($days[$e->id] ?? collect())->keyBy(fn ($d) => $d->date->toDateString());
            $late = []; $absent = []; $early = [];
            for ($d = Carbon::parse(max($start, $e->hire_date?->toDateString() ?? $start).' 12:00:00'); $d->toDateString() <= $to; $d->addDay()) {
                $date = $d->toDateString();
                $r = $rows->get($date);
                $c = DayCalc::day($date, $r?->in_at, $r?->out_at, $e->department_id);
                $st = Attendance::status($r, $c, $date, $e, isset($leave[$e->id][$date]));
                if ($st === 'absent') $absent[] = $date;
                if ($st === 'late') $late[] = [$date, $c['late']];
                if ($st === 'present' || $st === 'late') if ($c['early'] > 0) $early[] = $date;
            }
            $add = function (string $auto, array $dates, string $ar, string $en) use (&$out, $types, $e, $existing) {
                $t = $types->get($auto);
                if (! $t || isset($existing[$e->id.':'.$t->id])) return;
                $g = self::grade($e, $t, end($dates));
                $out[] = ['employee' => ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en]], 'type' => self::typeRow($t),
                    'dates' => array_values($dates), 'count' => count($dates), 'summary' => ['ar' => $ar, 'en' => $en], 'occurred_on' => end($dates),
                    'occurrence' => $g['occurrence'], 'suggested' => $g['penalty'] + ['label' => ['ar' => ViolationCatalog::label($g['penalty']), 'en' => ViolationCatalog::label($g['penalty'], 'en')]]];
            };
            if (count($late) >= (int) $p['suggest_late']) {
                $max = max(array_column($late, 1));
                $auto = $max > 60 ? 'late_over' : ($max > 30 ? 'late60' : ($max > 15 ? 'late30' : 'late15'));
                $n = count($late);
                $add($auto, array_column($late, 0), "تأخر غير مبرر {$n} مرات هذا الشهر (أطوله {$max} د)", "Unjustified lateness {$n} times this month (longest {$max} min)");
            }
            if (count($absent) >= (int) $p['suggest_absent']) $add('absent', $absent, 'غياب غير مبرر '.count($absent).' يوم', 'Unjustified absence: '.count($absent).' day(s)');
            if (count($early) >= (int) $p['suggest_early']) $add('early', $early, 'خروج مبكر '.count($early).' مرات', 'Left early '.count($early).' times');
        }
        return $out;
    }

    public static function typeRow(ViolationType $t): array
    {
        return ['id' => $t->id, 'group' => $t->group, 'number' => $t->number, 'title' => ['ar' => $t->title_ar, 'en' => $t->title_en], 'article' => $t->article, 'auto' => $t->auto, 'active' => $t->active,
            'penalties' => array_map(fn ($p) => $p + ['label' => ['ar' => ViolationCatalog::label($p), 'en' => ViolationCatalog::label($p, 'en')]], $t->penalties ?? [])];
    }

    /** صف الحالة للعرض. $full = مع التحقيق والدفاع والخطوات */
    public static function row(DisciplineCase $c, ?User $viewer = null, bool $full = false): array
    {
        $pen = fn (?array $p) => $p ? $p + ['label' => ['ar' => ViolationCatalog::label($p), 'en' => ViolationCatalog::label($p, 'en')]] : null;
        $daysLeft = in_array($c->status, ['open', 'investigated'], true) ? (int) now()->startOfDay()->diffInDays($c->deadline, false) : null;
        $r = ['id' => $c->id, 'ref_no' => $c->ref_no, 'employee' => $c->employee ? ['id' => $c->employee->id, 'code' => $c->employee->code, 'name' => ['ar' => $c->employee->name, 'en' => $c->employee->name_en],
                'title' => ['ar' => $c->employee->title_ar, 'en' => $c->employee->title_en],
                'dept' => $c->employee->relationLoaded('department') && $c->employee->department ? ['ar' => $c->employee->department->name_ar, 'en' => $c->employee->department->name_en] : null] : null,
            'type' => $c->type ? self::typeRow($c->type) : null, 'occurred_on' => $c->occurred_on->toDateString(), 'discovered_on' => $c->discovered_on->toDateString(), 'deadline' => $c->deadline->toDateString(),
            'days_left' => $daysLeft, 'source' => $c->source, 'description' => $c->description, 'status' => $c->status, 'occurrence' => $c->occurrence,
            'suggested' => $pen($c->suggested), 'penalty' => $pen($c->penalty), 'deduct_days' => $c->deduct_days, 'capped' => $c->capped, 'month' => $c->month, 'reduce_reason' => $c->reduce_reason,
            'has_defense' => (bool) $c->defense, 'acknowledged_at' => $c->acknowledged_at?->toIso8601String(), 'decided_at' => $c->decided_at?->toIso8601String(),
            'opened_by' => $c->opener ? ['ar' => $c->opener->displayName('ar'), 'en' => $c->opener->displayName('en')] : null, 'created_at' => $c->created_at?->toIso8601String()];
        if ($full) {
            $pa = PenaltyApproval::where('discipline_case_id', $c->id)->latest('id')->first();
            $r += ['defense' => $c->defense, 'defended_at' => $c->defended_at?->toIso8601String(), 'investigation' => $c->investigation, 'close_reason' => $c->close_reason,
                'decided_by' => $c->decider ? ['ar' => $c->decider->displayName('ar'), 'en' => $c->decider->displayName('en')] : null,
                'signature' => $c->ack_signature_file_id ? Signature::dataUrl(\App\Models\StoredFile::find($c->ack_signature_file_id)) : null,
                'approval' => $pa ? ['status' => $pa->status, 'penalty' => $pen($pa->penalty), 'steps' => Workflow::steps($pa)] : null];
        }
        return $r;
    }
}
