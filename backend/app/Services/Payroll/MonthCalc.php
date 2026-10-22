<?php

namespace App\Services\Payroll;

use App\Models\Advance;
use App\Models\AttendanceDay;
use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Attendance\Attendance;
use App\Services\Attendance\DayCalc;
use App\Services\Leave\LeaveService;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * حساب شهر موظف للكشف الشهري (D-01، SRS §10.10) — كل القيم من السياسات النافذة في الشهر:
 * - الحضور والغياب (غير المبرر / المبرر) والأيام الناقصة والإجازات حسب نسبة أجر نوعها.
 * - التأخير بجدول late_deduction، مع إعفاء exempt_per_month مرة بالشهر لتأخير مبرر حتى exempt_upto دقيقة (HR.01-01).
 * - الإضافي: يوم الراحة/العطلة بنسبته؛ وأيام العمل فقط ما تجاوز weekly_hours ساعة حضور بالأسبوع، وكله بحد التكليف المعتمد F-04
 *   (إن كان requires_assignment) وبحد daily_max يومياً.
 * - حسم المخالفات (M17) المقرر لهذا الشهر بأيام أجر.
 * - المبالغ تقديرية (حتى التكامل مع ZERP): الأجر اليومي = الراتب ÷ month_days، والساعة ÷ day_hours.
 */
class MonthCalc
{
    public static function range(string $month): array
    {
        $from = Carbon::parse($month.'-01 12:00:00');
        return [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()];
    }

    /** آخر يوم يُحسب: نهاية الشهر، أو أمس للشهر الجاري */
    public static function until(string $month, ?string $upTo = null): string
    {
        [, $to] = self::range($month);
        $limit = $upTo ?? now()->subDay()->toDateString();
        return min($to, $limit);
    }

    /** @return array{data: array, money: array} */
    public static function compute(Employee $e, string $month, ?string $upTo = null): array
    {
        [$from, $to] = self::range($month);
        $end = self::until($month, $upTo);
        $hire = $e->hire_date?->toDateString() ?? $from;
        $pay = Settings::policy('payroll', $to);
        $start = max($from, $hire, $pay['start_date'] ?? $from);
        $lateP = Settings::policy('late_deduction', $to);
        $otP = Settings::policy('overtime', $to);

        $days = AttendanceDay::where('employee_id', $e->id)->whereBetween('date', [$start, $end])->get()->keyBy(fn ($d) => $d->date->toDateString());
        $paid = [];
        LeaveRequest::where('employee_id', $e->id)->where('status', 'approved')->whereNotIn('type', ['hourly', 'justify'])->where('to', '>=', $start)->where('from', '<=', $end)->get()
            ->each(function (LeaveRequest $l) use (&$paid, $start, $end) {
                $pct = (int) (LeaveService::type($l->type)['paid'] ?? 100);
                for ($d = Carbon::parse(max($l->from->toDateString(), $start).' 12:00:00'); $d->toDateString() <= min($l->to->toDateString(), $end); $d->addDay()) $paid[$d->toDateString()] = $pct;
            });
        $assigned = OvertimeRequest::where('employee_id', $e->id)->where('status', 'approved')->whereBetween('date', [$start, $end])->get()
            ->groupBy(fn ($o) => $o->date->toDateString())->map(fn ($g) => (int) $g->sum('minutes'))->all();

        $t = ['work_days' => 0, 'present' => 0, 'absent' => 0, 'justified_absent' => 0, 'incomplete' => 0, 'leave' => 0, 'leave_unpaid' => 0.0,
            'late_days' => 0, 'late' => 0, 'exempted' => 0, 'exempt_min' => 0, 'deduct' => 0.0, 'worked' => 0,
            'ot_raw' => 0, 'ot_assigned' => array_sum($assigned), 'ot_paid' => 0, 'ot_weighted' => 0.0, 'incomplete_dates' => [], 'from' => $start, 'to' => $end];
        $weeks = [];
        $daily = (int) $otP['daily_max'];
        for ($d = Carbon::parse($start.' 12:00:00'); $d->toDateString() <= $end; $d->addDay()) {
            $date = $d->toDateString();
            $r = $days->get($date);
            $c = DayCalc::day($date, $r?->in_at, $r?->out_at, $e->department_id);
            $st = Attendance::status($r, $c, $date, $e, isset($paid[$date]));
            if (! $c['rest']) $t['work_days']++;
            if ($st === 'leave' && ! $c['rest']) { $t['leave']++; $t['leave_unpaid'] += (100 - ($paid[$date] ?? 100)) / 100; }
            if ($st === 'absent') $t['absent']++;
            if ($st === 'justified' && ! $r?->in_at) $t['justified_absent']++;
            if ($st === 'incomplete') { $t['incomplete']++; $t['incomplete_dates'][] = $date; }
            if ($r?->in_at) $t['present']++;
            if ($c['late'] > 0 && in_array($st, ['late', 'justified'], true)) {
                $t['late'] += $c['late']; $t['late_days']++;
                if ($st === 'justified' && $c['late'] <= $lateP['exempt_upto'] && $t['exempted'] < $lateP['exempt_per_month']) { $t['exempted']++; $t['exempt_min'] += $c['late']; }
                else $t['deduct'] += $c['deduct'];
            }
            $t['worked'] += $c['worked'];
            $wk = $d->copy()->startOfWeek(Carbon::SATURDAY)->toDateString();
            $weeks[$wk] ??= ['presence' => 0, 'days' => []];
            if (! $c['rest'] && $r?->in_at && $r?->out_at) $weeks[$wk]['presence'] += (int) round($r->in_at->diffInMinutes($r->out_at));
            if ($c['ot'] > 0) {
                $t['ot_raw'] += $c['ot'];
                $cap = min($c['ot'], $daily, $otP['requires_assignment'] ? ($assigned[$date] ?? 0) : PHP_INT_MAX);
                if ($c['rest']) { $t['ot_paid'] += $cap; $t['ot_weighted'] += $cap * (1 + $c['ot_rate']); }
                else $weeks[$wk]['days'][] = [$cap, $c['ot_rate']];
            }
        }
        // أيام العمل: الإضافي فقط بعد weekly_hours ساعة حضور في الأسبوع
        foreach ($weeks as $w) {
            $allow = max(0, $w['presence'] - (int) $otP['weekly_hours'] * 60);
            foreach ($w['days'] as [$cap, $rate]) { $a = min($cap, $allow); $allow -= $a; $t['ot_paid'] += $a; $t['ot_weighted'] += $a * (1 + $rate); }
        }
        $t['deduct'] = round($t['deduct'], 1);
        $t['leave_unpaid'] = round($t['leave_unpaid'], 2);
        $t['ot_weighted'] = round($t['ot_weighted'], 1);

        // المبالغ التقديرية
        $salary = (float) $e->salary;
        $dayWage = $salary / max(1, (int) $pay['month_days']);
        $hour = $dayWage / max(1, (int) $pay['day_hours']);
        $monthLen = Carbon::parse($from)->daysInMonth;
        $firstPaid = max($from, $hire);
        $employed = $firstPaid > $to ? 0 : Carbon::parse($firstPaid)->diffInDays(Carbon::parse($to)) + 1;
        $ratio = min(1, $employed / $monthLen);
        $base = $salary * $ratio;
        // M17: حسم المخالفات المقرر لهذا الشهر (بعد سقف cap_days)
        $t['discipline_days'] = round((float) DisciplineCase::where('employee_id', $e->id)->where('month', $month)->where('status', 'decided')->sum('deduct_days'), 2);
        $advance = (float) Advance::where('employee_id', $e->id)->where('month', $month)->whereIn('status', ['open', 'deducted'])->sum('amount');
        $m = ['base' => $base, 'ot_pay' => $t['ot_weighted'] / 60 * $hour, 'late' => $t['deduct'] / 60 * $hour,
            'absent' => ($t['absent'] * (float) $pay['absent_days'] + $t['justified_absent'] * (float) $pay['justified_absent_days']) * $dayWage,
            'leave' => $t['leave_unpaid'] * $dayWage, 'insurance' => $base * (float) $pay['insurance_base'] * (float) $pay['insurance_rate'], 'advance' => $advance, 'discipline' => $t['discipline_days'] * $dayWage];
        $m['net'] = $m['base'] + $m['ot_pay'] - $m['late'] - $m['absent'] - $m['leave'] - $m['insurance'] - $m['advance'] - $m['discipline'];
        $m = array_map(fn ($v) => round($v, 2), $m) + ['day' => round($dayWage, 2), 'hour' => round($hour, 2), 'currency' => $e->currency];
        return ['data' => $t, 'money' => $m];
    }
}
