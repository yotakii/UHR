<?php

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\EmployeeCompetency;
use App\Models\JobCompetency;
use App\Models\LeaveRequest;
use App\Models\TdEnrollment;
use App\Models\TdProgram;
use App\Models\TdSession;
use App\Models\User;
use App\Services\People\Directory;
use App\Support\Perm;
use App\Support\Settings;
use Carbon\Carbon;

/** قواعد الموديول المشتركة: الإعدادات، الصلاحيات، الأهلية والتعارضات، شرائح التعهد، الفجوات، درجة الأولوية */
class Td
{
    public const PATHS = ['qualification', 'training', 'development'];
    public const DELIVERY = ['internal', 'internal_ext', 'external', 'ojt', 'stage', 'elearning', 'coaching', 'event'];
    public const CAUSES = ['knowledge', 'motivation', 'resources', 'procedures'];
    public const EVIDENCE = ['survey', 'interview', 'observation', 'appraisal', 'kpi'];

    public static function cfg(): array { return Settings::merged('training'); }
    public static function manages(User $u): bool { return Perm::can($u, 'training', 'edit') && Perm::scope($u, 'training') === 'all'; }
    public static function scoped(User $u) { return Directory::scoped($u, 'training'); }
    public static function inScope(User $u, ?int $employeeId): bool { return $employeeId && self::scoped($u)->whereKey($employeeId)->exists(); }

    /** BR-01 + المتطلبات المسبقة + التعارضات (FR-TD-059/073). @return array{block: string[], warn: string[]} */
    public static function eligibility(Employee $e, TdSession $s, TdProgram $p): array
    {
        $cfg = self::cfg(); $block = []; $warn = [];
        if ($cfg['probation_block'] && in_array($e->status, ['probation', 'onboarding'], true) && $p->path !== 'qualification' && ! $p->mandatory)
            $block[] = 'probation';
        foreach ($p->prerequisites ?? [] as $pre) {
            $done = TdEnrollment::where('employee_id', $e->id)->where('result', 'passed')->whereHas('session', fn ($q) => $q->where('program_id', $pre))->exists();
            if (! $done) { $block[] = 'prerequisite'; break; }
        }
        $from = $s->starts_on->toDateString(); $to = $s->ends_on->toDateString();
        if (LeaveRequest::where('employee_id', $e->id)->whereIn('status', ['approved', 'pending_manager', 'pending_hr', 'pending_exec'])->where('from', '<=', $to)->where('to', '>=', $from)->exists()) $warn[] = 'leave';
        if (TdEnrollment::where('employee_id', $e->id)->whereIn('status', ['confirmed', 'pending_commitment', 'nominated'])->where('session_id', '!=', $s->id)
            ->whereHas('session', fn ($q) => $q->whereNotIn('status', ['cancelled', 'closed'])->where('starts_on', '<=', $to)->where('ends_on', '>=', $from))->exists()) $warn[] = 'other_program';
        foreach ($cfg['peak_seasons'] as $ps) if (self::inSeason($from, $to, $ps)) { $warn[] = 'peak'; break; }
        if (\App\Models\Holiday::whereBetween('date', [$from, $to])->exists()) $warn[] = 'holiday';
        return ['block' => $block, 'warn' => $warn];
    }

    private static function inSeason(string $from, string $to, array $ps): bool
    {
        $md = fn ($d) => substr($d, 5);
        return ! ($md($to) < ($ps['from'] ?? '13') || $md($from) > ($ps['to'] ?? '00'));
    }

    /** BR-06: تعهد مطلوب؟ ومدته من الشرائح (بعملة الشرائح) */
    public static function commitmentFor(Employee $e, float $cost, string $currency, bool $requested): ?int
    {
        $cfg = self::cfg();
        if ($cost <= 0) return null;
        $salary = (float) $e->salary;
        $salaryIn = $e->currency === $currency ? $salary : self::convert($salary, $e->currency, $currency);
        $need = ($salaryIn > 0 && $cost >= $salaryIn * (float) $cfg['commitment_pct'] / 100) || ($requested && $cfg['commitment_on_request']);
        if (! $need) return null;
        $tc = $cfg['commitment_currency'];
        $c = $currency === $tc ? $cost : self::convert($cost, $currency, $tc);
        foreach ($cfg['commitment_tiers'] as $t) if ($c >= (float) $t['from'] && ((float) $t['to'] <= 0 || $c < (float) $t['to'])) return (int) $t['months'];
        return (int) (end($cfg['commitment_tiers'])['months'] ?? 12);
    }

    public static function convert(float $amount, ?string $from, string $to): float
    {
        if (! $from || $from === $to) return $amount;
        $r = \App\Models\ExchangeRate::lookup($from, $to, now()->toDateString());
        return $r ? $amount * $r : $amount;
    }

    /** BR-13: المبلغ المستحق عند الإخلال — كامل أو نسبي من الأشهر المتبقية */
    public static function recovery(\App\Models\TdCommitment $c, ?string $on = null): float
    {
        if (! $c->ends_on) return $c->cost;
        $on = Carbon::parse($on ?? now()->toDateString());
        if ($on->gte($c->ends_on)) return 0.0;
        if ($c->mode === 'full') return $c->cost;
        $left = max(0, $on->diffInDays($c->ends_on, false));
        $total = max(1, $c->starts_on ? $c->starts_on->diffInDays($c->ends_on) : $c->months * 30);
        return round($c->cost * min(1, $left / $total), 2);
    }

    /** المستوى المطلوب لكل كفاءة في وظيفة الموظف */
    public static function required(Employee $e): array
    {
        $job = $e->getAttributes()['job_id'] ?? null;
        return $job ? JobCompetency::where('job_id', $job)->pluck('level', 'competency_id')->map(fn ($v) => (int) $v)->all() : [];
    }

    public static function current(Employee $e): array { return EmployeeCompetency::where('employee_id', $e->id)->pluck('level', 'competency_id')->map(fn ($v) => (int) $v)->all(); }

    public static function setLevel(Employee $e, int $competency, int $level, string $source, ?string $ref = null): void
    {
        EmployeeCompetency::updateOrCreate(['employee_id' => $e->id, 'competency_id' => $competency], ['level' => max(1, min(5, $level)), 'source' => $source, 'source_ref' => $ref]);
    }

    /** FR-TD-029: درجة الأولوية = أثر × فجوة × عدد المتأثرين × الإلحاح، بأوزان قابلة للتعديل (كل عامل 1..5) */
    public static function score(array $f): float
    {
        $w = self::cfg()['weights']; $s = 1.0;
        foreach (['impact', 'gap', 'affected', 'urgency'] as $k) $s *= max(1, min(5, (int) ($f[$k] ?? 1))) ** (float) ($w[$k] ?? 1);
        return round($s, 2);
    }

    public static function priorityOf(float $score): string
    {
        return $score >= 300 ? 'urgent' : ($score >= 100 ? 'high' : ($score >= 20 ? 'normal' : 'low'));
    }

    /** BR-02: ناجح = علامة ≥ حد النجاح وحضور ≥ حد الحضور (دون اختبار يكفي الحضور) */
    public static function result(TdEnrollment $en, TdProgram $p): array
    {
        $cfg = self::cfg();
        $att = (float) ($en->attendance_pct ?? 0);
        $minAtt = (float) ($p->attendance_min ?? $cfg['attendance_min']);
        $pass = (float) ($p->pass_mark ?? $cfg['pass_mark']);
        if ($att < $minAtt) return ['failed', 'attendance'];
        if ($p->has_test && ($en->post_score === null || $en->post_score < $pass)) return ['failed', 'score'];
        return ['passed', null];
    }
}
