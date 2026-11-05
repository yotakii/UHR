<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Services\Attendance\DayCalc;
use App\Services\Attendance\WorkSchedules;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** دفعة ملاحظات الاختبار 15c — جداول الدوام والورديات والقوالب الموسمية، الفروع والمناطق الزمنية، إحصاءات الحضور، الأشهر السابقة، إجازة المدير لفريقه */
class SchedulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        WorkSchedules::forget();
    }

    protected function tearDown(): void { Carbon::setTestNow(); WorkSchedules::forget(); parent::tearDown(); }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function samer(): Employee { return Employee::findOrFail(User::where('email', 'samer.halabi@alfarada.sy')->value('employee_id')); }

    /** 24 و76: جدول دائم، ورديات لفترة، قالب موسمي — والأولوية: الإسناد بفترة ← جدول الموظف ← السياسة */
    public function test_schedules_shifts_and_assignment_priority(): void
    {
        $this->login('hr@alfarada.sy');
        $samer = $this->samer();
        $mgr = $this->postJson('/api/hr/schedules', ['name_ar' => 'دوام الإدارة', 'kind' => 'fixed', 'shifts' => [['key' => 'day', 'ar' => 'نهاري', 'start' => 600, 'end' => 1080]],
            'work_days' => [0, 1, 2, 3, 4]])->assertCreated()->json('data');
        $shifts = $this->postJson('/api/hr/schedules', ['name_ar' => 'ورديات', 'kind' => 'shifts', 'work_days' => [0, 1, 2, 3, 4, 5, 6],
            'shifts' => [['key' => 'am', 'ar' => 'صباحية', 'start' => 420, 'end' => 900], ['key' => 'pm', 'ar' => 'مسائية', 'start' => 900, 'end' => 1380, 'grace' => 10]]])->assertCreated()->json('data');
        $this->postJson('/api/hr/schedules', ['name_ar' => 'ليلية خاطئة', 'kind' => 'fixed', 'shifts' => [['key' => 'n', 'ar' => 'ليل', 'start' => 1320, 'end' => 360]], 'work_days' => [1]])
            ->assertStatus(422)->assertJsonValidationErrors('shifts.0.end');

        // جدول دائم
        $this->postJson('/api/hr/schedules/assign', ['employee_ids' => [$samer->id], 'work_schedule_id' => $mgr['id'], 'mode' => 'permanent'])->assertOk();
        WorkSchedules::forget();
        $r = WorkSchedules::resolve($samer->fresh(), '2026-10-13');
        $this->assertSame([600, 1080, 'employee'], [$r['shift']['start'], $r['shift']['end'], $r['source']]);
        // الوصول 10:20 = تأخير 20 دقيقة على جدوله (لا على 08:00 للسياسة)
        $this->assertSame(20, DayCalc::day('2026-10-13', Carbon::parse('2026-10-13 10:20', 'Asia/Damascus'), null, $samer->fresh())['late']);

        // وردية مسائية لأسبوع — تتقدم على الجدول الدائم، والجمعة يوم عمل فيها
        $this->postJson('/api/hr/schedules/assign', ['employee_ids' => [$samer->id], 'work_schedule_id' => $shifts['id'], 'mode' => 'period', 'shift_key' => 'pm', 'from' => '2026-10-15', 'to' => '2026-10-21'])->assertOk();
        $this->postJson('/api/hr/schedules/assign', ['employee_ids' => [$samer->id], 'work_schedule_id' => $shifts['id'], 'mode' => 'period', 'shift_key' => 'am', 'from' => '2026-10-20', 'to' => '2026-10-25'])
            ->assertStatus(422)->assertJsonPath('code', 'assignment_overlap');
        WorkSchedules::forget();
        $r = WorkSchedules::resolve($samer->fresh(), '2026-10-16');
        $this->assertSame([900, 10, 'assignment', 'pm'], [$r['shift']['start'], $r['shift']['grace'], $r['source'], $r['shift_key']]);
        $this->assertFalse(DayCalc::weekend('2026-10-16', $samer->fresh()));
        $this->assertSame(600, WorkSchedules::resolve($samer->fresh(), '2026-10-22')['shift']['start']);

        // المناوبات الأسبوعية
        $roster = $this->getJson('/api/hr/schedules/roster?from=2026-10-15&days=7')->assertOk()->json();
        $row = collect($roster['data'])->firstWhere('id', $samer->id);
        $this->assertSame(['pm', 900], [$row['days'][0]['shift'], $row['days'][0]['start']]);

        // الجدول المسند لا يُحذف
        $this->deleteJson("/api/hr/schedules/{$mgr['id']}")->assertStatus(409)->assertJsonPath('code', 'schedule_in_use');
    }

    /** 80: فرع خاص بالشركة بمنطقة زمنية — أوقات حضور موظفيه تُقرأ بتوقيته */
    public function test_branch_timezone_applies_to_attendance(): void
    {
        $this->login('admin@alfarada.sy');
        $b = collect($this->postJson('/api/hr/branches', ['code' => 'dubai', 'name_ar' => 'دبي', 'name_en' => 'Dubai', 'timezone' => 'Asia/Dubai'])->assertOk()->json('data'))->firstWhere('code', 'dubai');
        $this->assertTrue($b['own']);
        $samer = $this->samer();
        $samer->update(['location_id' => $b['id']]);
        WorkSchedules::forget();
        $this->assertSame('Asia/Dubai', WorkSchedules::tz($samer->fresh()));
        // 06:00 بتوقيت دمشق = 07:00 في دبي (UTC+4 مقابل UTC+3)
        $c = DayCalc::day('2026-10-13', Carbon::parse('2026-10-13 06:00', 'Asia/Damascus'), Carbon::parse('2026-10-13 15:00', 'Asia/Damascus'), $samer->fresh());
        $this->assertSame(60 * 9, $c['worked'] + 60);   // دوام 9 ساعات ناقص استراحة ساعة
        $this->assertSame($samer->company_id, Location::withoutGlobalScopes()->where('code', 'dubai')->value('company_id'));
        // فرع شركة أخرى لا يظهر
        $this->login('hr@atmata.sy');
        $this->assertNotContains('dubai', array_column($this->getJson('/api/hr/branches')->assertOk()->json('data'), 'code'));
    }

    /** 23 و31: إحصائية الفترة وربطها بالكشف، فلتر الفرع، اتجاه أسبوعي */
    public function test_period_totals_branch_filter_and_weekly_trend(): void
    {
        $this->login('hr@alfarada.sy');
        $p = $this->getJson('/api/hr/attendance/period?from=2026-10-01&to=2026-10-11')->assertOk()->json();
        $this->assertSame(count($p['rows']), $p['totals']['employees']);
        $this->assertSame(array_sum(array_column($p['rows'], 'present')), $p['totals']['present']);
        $this->assertSame('2026-10', $p['payroll']['month']);
        $loc = Employee::where('status', 'active')->value('location_id');
        $only = $this->getJson("/api/hr/attendance/period?from=2026-10-01&to=2026-10-11&branch={$loc}")->assertOk()->json('rows');
        $this->assertSame(Employee::current()->where('location_id', $loc)->where('hire_date', '<=', '2026-10-11')->count(), count($only));
        $t = $this->getJson('/api/hr/attendance/trend?weeks=4')->assertOk()->json('data');
        $this->assertCount(4, $t);
        $this->assertGreaterThan(0, collect($t)->sum('expected'));
    }

    /** 06: الموظف يرى أي شهر سابق منذ مباشرته */
    public function test_employee_sees_previous_months(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $r = $this->getJson('/api/me/attendance?month=2026-10')->assertOk()->json();
        $this->assertContains('2026-10', $r['months']);
        $this->assertSame('2026-10', $r['month']['month']);
        $this->assertSame(AttendanceDay::where('employee_id', $this->samer()->id)->whereBetween('date', ['2026-10-01', '2026-10-12'])->whereNotNull('in_at')->count(), $r['month']['summary']['present']);
        $this->getJson('/api/me/attendance?month=2030-01')->assertStatus(422);
    }

    /** 60: المدير يقدّم إجازة طارئة لموظف من فريقه — مستواه يُعد موافقة، والأنواع ذات الإشعار المسبق مرفوضة */
    public function test_manager_requests_emergency_leave_for_team_member(): void
    {
        $samer = $this->samer();
        $mgrUser = User::where('employee_id', $samer->manager_id)->firstOrFail();
        $this->login($mgrUser->email);
        $sub = Employee::current()->where('department_id', $samer->department_id)->whereNotIn('id', [$samer->id, $samer->manager_id])->value('id');
        $this->postJson('/api/me/team/leave', ['employee_id' => $samer->id, 'type' => 'annual', 'from' => '2026-10-12', 'to' => '2026-10-12', 'reason' => 'ظرف طارئ', 'substitute_id' => $sub])
            ->assertStatus(422)->assertJsonPath('code', 'team_leave_type');
        $r = $this->postJson('/api/me/team/leave', ['employee_id' => $samer->id, 'type' => 'emergency', 'from' => '2026-10-12', 'to' => '2026-10-12', 'reason' => 'ظرف عائلي طارئ', 'substitute_id' => $sub])->assertCreated();
        $l = LeaveRequest::findOrFail($r->json('data.id'));
        $this->assertSame([$samer->id, $mgrUser->id], [$l->employee_id, $l->requested_by]);
        $this->assertNotContains('manager', $l->steps()->pluck('role_key')->all());
        $this->assertNotSame('pending_manager', $l->status);
        // موظف ليس من فريقه
        $other = Employee::current()->where('manager_id', '!=', $samer->manager_id)->where('id', '!=', $samer->manager_id)->firstOrFail();
        $this->postJson('/api/me/team/leave', ['employee_id' => $other->id, 'type' => 'emergency', 'from' => '2026-10-12', 'reason' => 'ظرف عائلي طارئ'])->assertStatus(403);
    }
}
