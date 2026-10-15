<?php

namespace App\Services\Attendance;

use App\Support\Settings;
use Carbon\Carbon;

/**
 * حساب يوم الدوام — نقل calcDay من rules.ts بمعاملات من السياسة النافذة بتاريخ اليوم (الوردية، السماح، الاستراحة، الإضافي، أيام العمل).
 * يُكمَّل بجدول حسم التأخير وكشف الشهر في المهمة 07 (SRS §10.6، §10.10).
 */
class DayCalc
{
    /** @return array{status: string, late: int, early: int, worked: int, ot: int, ot_rate: float} */
    public static function day(string $date, Carbon|string|null $in = null, Carbon|string|null $out = null): array
    {
        $shift = Settings::policy('shift', $date);
        $rates = Settings::policy('overtime_rates', $date);
        $weekend = self::weekend($date);
        if (! $in) return ['status' => $weekend ? 'weekend' : 'absent', 'late' => 0, 'early' => 0, 'worked' => 0, 'ot' => 0, 'ot_rate' => 0.0];
        $i = self::mins($in);
        $o = $out ? self::mins($out) : null;
        $late = ! $weekend && $i > $shift['start'] + $shift['grace'] ? $i - $shift['start'] : 0;
        $early = ! $weekend && $o !== null && $o < $shift['end'] ? $shift['end'] - $o : 0;
        $worked = $o !== null ? $o - $i : 0;
        if ($worked > $shift['break_after']) $worked -= $shift['break'];
        $ot = 0; $rate = 0.0;
        if ($o !== null) {
            if ($weekend) { $ot = $worked; $rate = (float) $rates['rest']; }
            elseif ($o - $shift['end'] >= $shift['ot_threshold']) { $ot = $o - $shift['end']; $rate = (float) ($o > $shift['night_from'] ? $rates['night'] : $rates['day']); }
        }
        return ['status' => $weekend ? 'weekend' : ($late ? 'late' : 'present'), 'late' => $late, 'early' => $early, 'worked' => $worked, 'ot' => $ot, 'ot_rate' => $rate];
    }

    public static function weekend(string $date): bool
    {
        return ! in_array(Carbon::parse($date.' 12:00:00')->dayOfWeek, Settings::policy('work_days', $date), true);
    }

    /** آخر n أيام عمل حتى $today (شاملة)، من الأقدم للأحدث */
    public static function workDays(string $today, int $n): array
    {
        $out = [];
        for ($d = Carbon::parse($today.' 12:00:00'); count($out) < $n; $d->subDay()) if (! self::weekend($d->toDateString())) array_unshift($out, $d->toDateString());
        return $out;
    }

    private static function mins(Carbon|string $t): int
    {
        $c = $t instanceof Carbon ? $t->copy()->setTimezone(config('app.timezone')) : Carbon::parse($t);
        return $c->hour * 60 + $c->minute;
    }
}
