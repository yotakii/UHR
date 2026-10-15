<?php

namespace Tests\Unit;

use App\Services\Attendance\DayCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** حساب يوم الدوام = calcDay في الديمو بالسياسة الافتراضية (القيم المتوقعة مولّدة من rules.ts الأصلي) */
class DayCalcTest extends TestCase
{
    use RefreshDatabase;

    // [date, in, out] => [status, late, early, worked, ot, otRate]
    private const CASES = [
        [['2026-10-12', '07:55', '16:05'], ['present', 0, 0, 430, 0, 0]],
        [['2026-10-12', '08:15', '16:00'], ['present', 0, 0, 405, 0, 0]],
        [['2026-10-12', '08:16', '15:30'], ['late', 16, 30, 374, 0, 0]],
        [['2026-10-12', '08:40', null], ['late', 40, 0, 0, 0, 0]],
        [['2026-10-12', '07:30', '16:29'], ['present', 0, 0, 479, 0, 0]],
        [['2026-10-12', '07:30', '16:30'], ['present', 0, 0, 480, 30, 0.25]],
        [['2026-10-12', '09:00', '22:30'], ['late', 60, 0, 750, 390, 0.5]],
        [['2026-10-12', '10:00', '14:00'], ['late', 120, 120, 240, 0, 0]],
        [['2026-10-16', '09:00', '13:00'], ['weekend', 0, 0, 240, 240, 1]],
        [['2026-10-17', '09:00', '15:30'], ['weekend', 0, 0, 330, 330, 1]],
        [['2026-10-12', null, null], ['absent', 0, 0, 0, 0, 0]],
        [['2026-10-16', null, null], ['weekend', 0, 0, 0, 0, 0]],
    ];

    public function test_matches_the_demo_rules_exactly(): void
    {
        foreach (self::CASES as [[$date, $in, $out], $exp]) {
            $r = DayCalc::day($date, $in ? "{$date} {$in}:00" : null, $out ? "{$date} {$out}:00" : null);
            $this->assertSame($exp, [$r['status'], $r['late'], $r['early'], $r['worked'], $r['ot'], $r['ot_rate'] + 0 == (int) $r['ot_rate'] ? (int) $r['ot_rate'] : $r['ot_rate']], "{$date} {$in}-{$out}");
        }
    }

    public function test_work_days_skip_the_weekend(): void
    {
        $this->assertSame(['2026-10-07', '2026-10-08', '2026-10-11', '2026-10-12'], DayCalc::workDays('2026-10-12', 4));
    }
}
