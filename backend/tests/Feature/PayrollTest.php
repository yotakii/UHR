<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\AttendanceDay;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MonthSheet;
use App\Models\Notice;
use App\Models\OvertimeRequest;
use App\Models\Policy;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Attendance\Attendance;
use App\Services\Payroll\MonthCalc;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الكشف الشهري (D-01)، السلفة، وطلبات الخدمات والشهادات (D-16) */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function emp(string $code): Employee { return Employee::allCompanies()->where('code', $code)->firstOrFail(); }

    /** موظف بشهر أيلول 2026 مضبوط يوماً بيوم (سياسة الديمو: الأحد–الخميس 08:00–16:00) */
    private function september(): Employee
    {
        $ref = $this->emp('UHR-1007');
        Tenant::set($ref->company);
        $policy = fn (string $k, array $v) => Policy::updateOrCreate(['key' => $k, 'effective_from' => '2020-01-01'], ['value' => $v + Settings::policy($k)]);
        $policy('shift', ['grace' => 0]);
        $policy('overtime', ['weekly_hours' => 10]);
        $policy('payroll', ['start_date' => null]);
        Settings::bumpPolicies();
        $e = Employee::create(['code' => 'UHR-1900', 'name' => 'اختبار الكشف', 'name_en' => 'Sheet Test', 'gender' => 'M', 'email' => 'sheet@alfarada.sy', 'title_ar' => 'محاسب', 'title_en' => 'Accountant',
            'department_id' => $ref->department_id, 'location_id' => $ref->location_id, 'hire_date' => '2026-01-01', 'salary' => 3000, 'currency' => 'USD', 'status' => 'active']);
        $day = fn (string $d, ?string $in, ?string $out, bool $j = false) => AttendanceDay::create(['employee_id' => $e->id, 'date' => $d, 'in_at' => $in ? "{$d} {$in}:00" : null, 'out_at' => $out ? "{$d} {$out}:00" : null, 'status' => 'present', 'justified' => $j]);
        foreach (['01' => '08:10', '02' => '08:05', '03' => '08:07', '06' => '08:12'] as $d => $in) $day("2026-09-{$d}", $in, '16:00', true);   // تأخير مبرر ≤ 15: 3 إعفاءات ثم حسم
        $day('2026-09-07', '08:20', '16:00');           // 20 د ×1
        $day('2026-09-08', '08:40', '16:00');           // 40 د ×1.5 = 60
        $day('2026-09-10', null, null, true);           // غياب مبرر — 09/09 بلا سجل = غياب
        $day('2026-09-04', '10:00', '14:00');           // الجمعة: إضافي يوم راحة 240 د
        $day('2026-09-13', '08:00', null);              // ناقص
        $day('2026-09-16', '08:00', '19:00');           // إضافي نهاري 180 د
        foreach (['17', '20', '21', '22', '23', '24', '27', '28', '29', '30'] as $d) $day("2026-09-{$d}", '08:00', '16:00');
        LeaveRequest::create(['employee_id' => $e->id, 'type' => 'unpaid', 'from' => '2026-09-14', 'to' => '2026-09-15', 'reason' => 'ظرف', 'status' => 'approved', 'days' => 2]);
        foreach (['2026-09-04', '2026-09-16'] as $d) OvertimeRequest::create(['employee_id' => $e->id, 'date' => $d, 'minutes' => 120, 'reason' => 'إغلاق الحسابات', 'status' => 'approved']);
        return $e;
    }

    public function test_month_calc_applies_the_company_rules(): void
    {
        $e = $this->september();
        $c = MonthCalc::compute($e, '2026-09');
        $d = $c['data']; $m = $c['money'];
        $this->assertSame([22, 3, 22, 92.0], [$d['work_days'], $d['exempted'], $d['exempt_min'], (float) $d['deduct']]);
        $this->assertSame([1, 1, 1, ['2026-09-13'], 2, 2.0], [$d['absent'], $d['justified_absent'], $d['incomplete'], $d['incomplete_dates'], $d['leave'], (float) $d['leave_unpaid']]);
        // يوم الراحة: 240 د بحد التكليف 120؛ الأربعاء: 180 د، الأسبوع 1140 د حضور − 600 = 540 مسموح، بحد التكليف 120
        $this->assertSame([420, 240, 240, 390.0], [$d['ot_raw'], $d['ot_assigned'], $d['ot_paid'], (float) $d['ot_weighted']]);
        $this->assertEquals(['base' => 3000, 'ot_pay' => 81.25, 'late' => 19.17, 'absent' => 100, 'leave' => 200, 'insurance' => 151.2, 'advance' => 0, 'net' => 2610.88],
            array_intersect_key($m, array_flip(['base', 'ot_pay', 'late', 'absent', 'leave', 'insurance', 'advance', 'net'])));
        // بلا شرط التكليف وبحد يومي 300 د: كل المسجل (240 الراحة + 180 الأربعاء ضمن المسموح أسبوعياً)
        Policy::where('key', 'overtime')->update(['value' => ['requires_assignment' => false, 'daily_max' => 300] + Settings::policy('overtime')]);
        OvertimeRequest::query()->delete(); Settings::bumpPolicies();
        $this->assertSame(420, MonthCalc::compute($e, '2026-09')['data']['ot_paid']);
    }

    public function test_sheet_lifecycle_blocks_incomplete_days_and_notifies(): void
    {
        $e = $this->september();
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/payroll')->assertForbidden();
        $this->login('hr@alfarada.sy');
        $this->getJson('/api/hr/payroll?month=2026-09')->assertOk()->assertJsonPath('sheet', null);
        $this->postJson('/api/hr/payroll', ['month' => '2026-11'])->assertUnprocessable()->assertJsonPath('code', 'month_future');
        $s = $this->postJson('/api/hr/payroll', ['month' => '2026-09'])->assertCreated()->json();
        $this->assertSame('calculated', $s['sheet']['status']);
        $this->assertSame(Employee::current()->count(), count($s['rows']));
        $this->assertContains(['employee_id' => $e->id, 'code' => 'UHR-1900', 'name' => ['ar' => 'اختبار الكشف', 'en' => 'Sheet Test'], 'date' => '2026-09-13'], $s['incomplete']);
        $this->assertTrue(Notice::where('link', 'payroll')->exists());
        $id = $s['sheet']['id'];
        $this->postJson("/api/hr/payroll/{$id}/close")->assertStatus(409)->assertJsonPath('code', 'sheet_state');
        $this->postJson("/api/hr/payroll/{$id}/approve")->assertOk()->assertJsonPath('sheet.status', 'approved');
        $this->postJson("/api/hr/payroll/{$id}/calculate")->assertStatus(409)->assertJsonPath('code', 'sheet_locked');
        $this->postJson("/api/hr/payroll/{$id}/close")->assertStatus(409)->assertJsonPath('code', 'incomplete_days');

        // التصحيح من الحضور ← لازم إعادة الاحتساب (بسبب) قبل الإغلاق
        $hr = User::where('email', 'hr@alfarada.sy')->first();
        foreach ($this->getJson("/api/hr/payroll?month=2026-09")->json('incomplete') as $x) app(Attendance::class)->correct(Employee::find($x['employee_id']), $x['date'], '08:00', '16:00', 'نسي البطاقة', $hr);
        $this->postJson("/api/hr/payroll/{$id}/close")->assertStatus(409)->assertJsonPath('code', 'sheet_stale');
        $this->postJson("/api/hr/payroll/{$id}/reopen", ['note' => 'قصير'])->assertUnprocessable();
        $this->postJson("/api/hr/payroll/{$id}/reopen", ['note' => 'إعادة بعد تصحيح الأيام الناقصة'])->assertOk()->assertJsonPath('sheet.status', 'calculated');
        $this->postJson("/api/hr/payroll/{$id}/calculate")->assertOk()->assertJsonPath('sheet.totals.incomplete', 0);
        $this->postJson("/api/hr/payroll/{$id}/approve")->assertOk();
        $this->postJson("/api/hr/payroll/{$id}/close")->assertOk()->assertJsonPath('sheet.status', 'closed');
        $this->assertTrue(AuditLog::where('action', 'payroll.closed')->exists());
        $this->get("/api/hr/payroll/{$id}/export")->assertStatus(403)->assertJsonPath('code', 'salary_locked');
        $this->unlockSalaries();
        $this->get("/api/hr/payroll/{$id}/export")->assertOk();

        // الموظف يرى كشفه المغلق وإشعار «كشفك جاهز»
        $this->login('samer.halabi@alfarada.sy');
        $p = $this->getJson('/api/me/payslips')->assertOk()->json();
        $this->assertSame(['2026-09', 'closed'], [$p['data'][0]['month'], $p['data'][0]['status']]);
        $this->assertSame('estimate', $p['current']['status']);
        $this->assertTrue(Notice::where('user_id', User::where('email', 'samer.halabi@alfarada.sy')->value('id'))->where('link', 'payslip')->exists());
        // الشهر الجاري لا يُغلق
        $this->login('hr@alfarada.sy');
        $o = $this->postJson('/api/hr/payroll', ['month' => '2026-10'])->assertCreated()->json('sheet.id');
        $this->postJson("/api/hr/payroll/{$o}/approve")->assertOk();
        $this->postJson("/api/hr/payroll/{$o}/close")->assertStatus(409)->assertJsonPath('code', 'month_open');
    }

    public function test_advance_is_limited_by_earned_pay_and_deducted(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $this->unlockSalaries();
        $d = $this->getJson('/api/me/services')->assertOk()->json();
        $av = $d['advance'];
        $this->assertGreaterThan(0, $av['available']);
        $this->assertLessThanOrEqual(1450 * 0.5, $av['available']);
        $this->postJson('/api/me/services', ['kind' => 'advance', 'amount' => $av['available'] + 50])->assertUnprocessable()->assertJsonPath('code', 'advance_limit');
        $r = $this->postJson('/api/me/services', ['kind' => 'advance', 'amount' => 100, 'note' => 'ظرف طارئ'])->assertCreated()->json('data');
        $this->assertSame('pending_hr', $r['status']);
        $this->assertSame(0, $this->getJson('/api/me/services')->json('advance.available'));   // سلفة واحدة بالشهر
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/me/approvals/{$r['step_id']}", ['decision' => 'approve'])->assertOk();
        $a = Advance::allCompanies()->firstOrFail();
        $this->assertSame([100.0, '2026-10', 'open'], [$a->amount, $a->month, $a->status]);
        $this->assertEquals(100, MonthCalc::compute($this->emp('UHR-1007'), '2026-10')['money']['advance']);
    }

    public function test_certificates_need_the_organisation_and_get_a_serial(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $to = ['name' => 'بنك بيمو', 'cr_no' => 'CR-5521', 'email' => 'hr@bemo.sy', 'phone' => '+963 11 999 0000'];
        $this->postJson('/api/me/services', ['kind' => 'salary_cert', 'addressee' => ['name' => 'بنك بيمو']])->assertUnprocessable()->assertJsonValidationErrors(['addressee.cr_no', 'addressee.email', 'addressee.phone']);
        $r = $this->postJson('/api/me/services', ['kind' => 'salary_cert', 'addressee' => $to, 'notify_consent' => true])->assertCreated()->json('data');
        $this->postJson('/api/me/services', ['kind' => 'salary_cert', 'addressee' => $to])->assertUnprocessable()->assertJsonPath('code', 'service_pending');
        $this->getJson("/api/me/services/{$r['id']}/certificate")->assertNotFound();
        $u = $this->postJson('/api/me/services', ['kind' => 'data_update', 'data' => ['phone' => '+963 944 000 111'], 'note' => 'رقم جديد'])->assertCreated()->json('data');

        $this->login('hr@alfarada.sy');
        $this->assertSame(3, $this->getJson('/api/hr/leaves?tab=services')->assertOk()->json('counts.services'));   // + طلب الديمو
        $this->postJson("/api/me/approvals/{$r['step_id']}", ['decision' => 'reject', 'note' => 'قصير'])->assertUnprocessable();
        $this->postJson("/api/me/approvals/{$r['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/me/approvals/{$u['step_id']}", ['decision' => 'approve'])->assertOk();
        $s = ServiceRequest::allCompanies()->findOrFail($r['id']);
        $this->assertSame(['approved', 'CRT-2026-0001'], [$s->status, $s->ref_no]);
        $this->assertSame('+963 944 000 111', $this->emp('UHR-1007')->phone);
        $this->assertTrue(AuditLog::where('action', 'certificate.issued')->exists());
        $this->getJson("/api/hr/services/{$r['id']}/certificate")->assertOk()->assertJsonPath('data.salary.amount', null)->assertJsonPath('data.masked', true);
        $this->unlockSalaries();
        $this->getJson("/api/hr/services/{$r['id']}/certificate")->assertOk()->assertJsonPath('data.salary.amount', 1450);

        $this->login('samer.halabi@alfarada.sy');
        $c = $this->getJson("/api/me/services/{$r['id']}/certificate")->assertOk()->json('data');
        $this->assertSame(['CRT-2026-0001', 'بنك بيمو', true, 'SAL'], [$c['ref_no'], $c['addressee']['name'], $c['notify_consent'], $c['form']]);
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->getJson("/api/me/services/{$r['id']}/certificate")->assertNotFound();
    }

    public function test_payroll_policy_is_versioned_and_validated(): void
    {
        $this->login('hr@alfarada.sy');
        $v = Settings::POLICY_DEFAULTS['payroll'];
        $this->postJson('/api/hr/policies', ['key' => 'payroll', 'effective_from' => '2026-11-01', 'value' => ['month_days' => 50] + $v])->assertUnprocessable()->assertJsonValidationErrors('month_days');
        $this->postJson('/api/hr/policies', ['key' => 'payroll', 'effective_from' => '2026-11-01', 'value' => ['absent_days' => 2] + $v])->assertOk();
        Tenant::set($this->emp('UHR-1007')->company);
        $this->assertEquals(2, Settings::policy('payroll', '2026-11-05')['absent_days']);
        $this->assertEquals(1, Settings::policy('payroll', '2026-10-05')['absent_days']);
    }
}
