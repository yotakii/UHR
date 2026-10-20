<?php

namespace Tests\Unit;

use App\Services\Attendance\DayCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** حساب يوم الدوام = calcDay في الديمو بسياسة الديمو (القيم المتوقعة مولّدة من rules.ts الأصلي) + حسم التأخير بجدول الشركة */
class DayCalcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCompany();
        \Database\Seeders\TestDataSeeder::demoPolicies();   // قيم الديمو (rules.ts SHIFT)
    }

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

    public function test_late_deduction_tiers_and_absence_follow_the_policy(): void
    {
        // جدول الشركة الافتراضي: 1–15 ×1، 16–30 ×1، 31–60 ×1.5، 61–120 ×2، أكثر من 120 غياب
        $this->assertSame([0.0, 10.0, 20.0, 60.0, 180.0, 0.0], array_map(fn ($m) => DayCalc::deduction($m), [0, 10, 20, 40, 90, 130]));
        $this->assertTrue(DayCalc::lateAbsent(130));
        \App\Models\Policy::create(['key' => 'late_deduction', 'effective_from' => '2026-01-01', 'value' => ['tiers' => [['from' => 1, 'to' => 60, 'factor' => 3]], 'exempt_upto' => 5, 'exempt_per_month' => 1, 'absent_after' => 60]]);
        \App\Support\Settings::flush();
        $this->assertSame(30.0, DayCalc::deduction(10, '2026-10-12'));
        $this->assertTrue(DayCalc::lateAbsent(61, '2026-10-12'));
    }

    public function test_department_schedule_and_holidays(): void
    {
        $dept = \App\Models\Department::create(['code' => 'ops', 'name_ar' => 'العمليات', 'name_en' => 'Ops']);
        \App\Models\Policy::create(['key' => "shift@{$dept->id}", 'effective_from' => '2026-01-01', 'value' => ['start' => 600, 'end' => 1080, 'grace' => 0, 'break' => 60, 'break_after' => 300, 'ot_threshold' => 30, 'night_from' => 1320]]);
        \App\Support\Settings::flush();
        $this->assertSame(40, DayCalc::day('2026-10-12', '2026-10-12 08:40:00', '2026-10-12 16:00:00')['late']);           // سياسة الشركة 08:00
        $this->assertSame(0, DayCalc::day('2026-10-12', '2026-10-12 08:40:00', '2026-10-12 18:00:00', $dept->id)['late']);  // الإدارة تبدأ 10:00
        \App\Models\Holiday::create(['date' => '2026-10-06', 'name_ar' => 'عيد تشرين', 'name_en' => 'Tishreen']);
        DayCalc::forgetHolidays(2026);
        $h = DayCalc::day('2026-10-06', '2026-10-06 09:00:00', '2026-10-06 13:00:00');
        $this->assertSame(['holiday', 240, 2.0], [$h['status'], $h['ot'], $h['ot_rate']]);
        $this->assertSame('holiday', DayCalc::day('2026-10-06')['status']);
    }
}
