<?php

namespace App\Services\Attendance;

use App\Models\Holiday;
use App\Support\Settings;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * حساب يوم الدوام — نقل calcDay من rules.ts بمعاملات من السياسة النافذة بتاريخ اليوم (الوردية، السماح، الاستراحة، الإضافي، أيام العمل)،
 * بجدول دوام الإدارة إن وُجد (shift@dept)، والعطلة الرسمية كيوم راحة بنسبة holiday، وحسم التأخير بجدول الشركة (SRS §10.6).
 */
class DayCalc
{
    /** @return array{status: string, late: int, early: int, worked: int, ot: int, ot_rate: float, deduct: float, holiday: bool, rest: bool} */
    public static function day(string $date, Carbon|string|null $in = null, Carbon|string|null $out = null, ?int $deptId = null): array
    {
        $shift = Settings::schedule('shift', $date, $deptId);
        $rates = Settings::policy('overtime_rates', $date);
        $holiday = self::holiday($date);
        $rest = $holiday || self::weekend($date, $deptId);
        $base = ['holiday' => $holiday, 'rest' => $rest, 'deduct' => 0.0];
        if (! $in) return ['status' => $rest ? ($holiday ? 'holiday' : 'weekend') : 'absent', 'late' => 0, 'early' => 0, 'worked' => 0, 'ot' => 0, 'ot_rate' => 0.0] + $base;
        $i = self::mins($in);
        $o = $out ? self::mins($out) : null;
        $late = ! $rest && $i > $shift['start'] + $shift['grace'] ? $i - $shift['start'] : 0;
        $early = ! $rest && $o !== null && $o < $shift['end'] ? $shift['end'] - $o : 0;
        $worked = $o !== null ? $o - $i : 0;
        if ($worked > $shift['break_after']) $worked -= $shift['break'];
        $ot = 0; $rate = 0.0;
        if ($o !== null) {
            if ($rest) { $ot = $worked; $rate = (float) ($holiday ? $rates['holiday'] : $rates['rest']); }
            elseif ($o - $shift['end'] >= $shift['ot_threshold']) { $ot = $o - $shift['end']; $rate = (float) ($o > $shift['night_from'] ? $rates['night'] : $rates['day']); }
        }
        $status = $rest ? ($holiday ? 'holiday' : 'weekend') : ($late ? 'late' : 'present');
        return ['status' => $status, 'late' => $late, 'early' => $early, 'worked' => $worked, 'ot' => $ot, 'ot_rate' => $rate, 'deduct' => self::deduction($late, $date), 'holiday' => $holiday, 'rest' => $rest];
    }

    /**
     * دقائق الحسم لتأخير صباحي حسب شرائح late_deduction (قبل إعفاءات الشهر — تُطبَّق في الكشف الشهري):
     * الدقيقة بدقيقة ×المعامل لشريحة التأخير كله، وما زاد على absent_after يُعامل غياباً (لا حسم دقائق).
     */
    public static function deduction(int $late, ?string $date = null): float
    {
        if ($late <= 0) return 0.0;
        $p = Settings::policy('late_deduction', $date);
        if ($late > $p['absent_after']) return 0.0;
        foreach ($p['tiers'] as $t) if ($late >= $t['from'] && $late <= $t['to']) return round($late * (float) $t['factor'], 1);
        $last = end($p['tiers']);
        return round($late * (float) ($last['factor'] ?? 1), 1);
    }

    /** تأخير يتجاوز absent_after = غياب (يُمنع من الدخول — جدول المخالفات 1/7) */
    public static function lateAbsent(int $late, ?string $date = null): bool
    {
        return $late > Settings::policy('late_deduction', $date)['absent_after'];
    }

    public static function weekend(string $date, ?int $deptId = null): bool
    {
        return ! in_array(Carbon::parse($date.' 12:00:00')->dayOfWeek, Settings::schedule('work_days', $date, $deptId), true);
    }

    public static function holiday(string $date): bool
    {
        $year = substr($date, 0, 4);
        $list = Cache::remember('holidays:'.Tenant::id().":{$year}", 300, fn () => Holiday::whereYear('date', (int) $year)->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all());
        return in_array($date, $list, true);
    }

    public static function forgetHolidays(int $year): void { Cache::forget('holidays:'.Tenant::id().":{$year}"); }

    /** آخر n أيام عمل حتى $today (شاملة)، من الأقدم للأحدث */
    public static function workDays(string $today, int $n): array
    {
        $out = [];
        for ($d = Carbon::parse($today.' 12:00:00'); count($out) < $n; $d->subDay()) if (! self::weekend($d->toDateString())) array_unshift($out, $d->toDateString());
        return $out;
    }

    /** وقت الطابع بدقائق اليوم بتوقيت الشركة */
    public static function mins(Carbon|string $t): int
    {
        $c = $t instanceof Carbon ? $t->copy()->setTimezone(config('app.timezone')) : Carbon::parse($t);
        return $c->hour * 60 + $c->minute;
    }
}
