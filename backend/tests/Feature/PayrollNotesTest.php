<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\LeaveRequest;
use App\Models\MonthSheetRow;
use App\Models\Policy;
use App\Services\Payroll\MonthCalc;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** دفعة الملاحظات 15d: المرضية بالشرائح، عملة الصرف وسعر الصرف، الفروقات والتحقق، قوالب التقارير */
class PayrollNotesTest extends TestCase
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

    public function test_sick_leave_is_paid_by_yearly_tiers(): void
    {
        $e = Employee::allCompanies()->where('code', 'UHR-1007')->firstOrFail();
        Tenant::set($e->company);
        Policy::updateOrCreate(['key' => 'leave_rules', 'effective_from' => '2020-01-01'], ['value' => ['sick_first_days' => 5, 'sick_first_paid' => 70, 'sick_second_days' => 5, 'sick_second_paid' => 80] + Settings::policy('leave_rules')]);
        Settings::bumpPolicies();
        // 3 أيام في آب تُحسب من الشريحة الأولى، ثم 9 أيام في أيلول
        LeaveRequest::create(['employee_id' => $e->id, 'type' => 'sick', 'from' => '2026-08-03', 'to' => '2026-08-05', 'days' => 3, 'reason' => 'مرض', 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $e->id, 'type' => 'sick', 'from' => '2026-09-01', 'to' => '2026-09-09', 'days' => 9, 'reason' => 'مرض', 'status' => 'approved']);
        $p = MonthCalc::sickPay($e, '2026-09-01', '2026-09-30');
        $this->assertSame([70, 70, 80, 80, 80, 80, 80, 0, 0], array_values($p));
        $this->assertSame('2026-09-01', array_key_first($p));
    }

    public function test_pay_currency_uses_the_rate_on_the_pay_date(): void
    {
        $this->login('hr@alfarada.sy');
        $id = $this->postJson('/api/hr/payroll', ['month' => '2026-09'])->assertCreated()->json('sheet.id');
        $this->postJson("/api/hr/payroll/{$id}/pay", ['currency' => 'SYP', 'pay_date' => '2026-09-30'])->assertOk()
            ->assertJsonPath('sheet.pay_currency', 'SYP')->assertJsonPath('sheet.missing_rates', ['USD']);
        $this->postJson("/api/hr/payroll/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'rate_missing');

        $this->postJson('/api/hr/payroll/rates', ['date' => '2026-09-15', 'from' => 'USD', 'to' => 'SYP', 'rate' => 13000])->assertCreated();
        $this->postJson('/api/hr/payroll/rates', ['date' => '2026-10-05', 'from' => 'USD', 'to' => 'SYP', 'rate' => 15000])->assertCreated();
        $this->postJson('/api/hr/payroll/rates', ['date' => '2026-10-05', 'from' => 'USD', 'to' => 'USD', 'rate' => 1])->assertUnprocessable();
        $r = $this->postJson("/api/hr/payroll/{$id}/pay", ['currency' => 'SYP', 'pay_date' => '2026-09-30'])->assertOk()->assertJsonPath('sheet.missing_rates', []);
        $this->assertSame(13000, (int) $r->json('sheet.rates.USD.rate'));
        $row = MonthSheetRow::allCompanies()->where('month_sheet_id', $id)->where('currency', 'USD')->firstOrFail();
        $this->assertEqualsWithDelta($row->money['net'] * 13000, $row->pay['net'], 1);
        $this->assertSame('SYP', $r->json('sheet.totals.currency'));
        $this->assertNull($r->json('sheet.totals.net'));   // المبالغ مقفلة قبل التحقق

        // مقلوب السعر يكفي: SYP → USD
        $this->postJson("/api/hr/payroll/{$id}/pay", ['currency' => 'USD', 'pay_date' => '2026-10-06'])->assertOk()->assertJsonPath('sheet.missing_rates', []);
        $this->assertEqualsWithDelta(1, ExchangeRate::lookup('SYP', 'USD', '2026-10-06')['rate'] * 15000, 0.0001);
        $this->postJson("/api/hr/payroll/{$id}/approve")->assertOk();
        $this->postJson("/api/hr/payroll/{$id}/pay", ['currency' => 'SYP', 'pay_date' => '2026-09-30'])->assertStatus(409)->assertJsonPath('code', 'sheet_locked');

        // إعداد الشركة: عملة الصرف ويوم الصرف للكشوف الجديدة
        $this->putJson('/api/hr/payroll/settings', ['currency' => 'SYP', 'pay_day' => 28, 'diff_alert_pct' => 15])->assertOk();
        $this->getJson('/api/hr/payroll/rates')->assertOk()->assertJsonPath('settings.pay_day', 28)->assertJsonCount(2, 'data');
    }

    public function test_diff_checks_and_report_templates(): void
    {
        $this->login('hr@alfarada.sy');
        $this->postJson('/api/hr/payroll', ['month' => '2026-08'])->assertCreated();
        $s = $this->postJson('/api/hr/payroll', ['month' => '2026-09'])->assertCreated();
        $s->assertJsonPath('diff.prev', '2026-08');
        $this->assertNotEmpty($s->json('diff.rows'));
        $codes = collect($s->json('checks'))->pluck('code')->unique()->all();
        $this->assertContains('no_bank', $codes);
        $this->assertNull($s->json('diff.rows.0.net'));   // مقفلة قبل التحقق
        $id = $s->json('sheet.id');

        $this->get("/api/hr/payroll/{$id}/report/bank")->assertStatus(403)->assertJsonPath('code', 'salary_locked');
        $this->unlockSalaries();
        $d = $this->getJson('/api/hr/payroll?month=2026-09')->assertOk();
        $this->assertNotNull($d->json('diff.rows.0.net'));
        foreach (['bank', 'insurance', 'departments', 'deductions', 'diff'] as $k) $this->get("/api/hr/payroll/{$id}/report/{$k}")->assertOk();
        $this->get("/api/hr/payroll/{$id}/report/nope")->assertNotFound();
        $this->get("/api/hr/payroll/{$id}/export")->assertOk();
        $this->assertTrue(\App\Models\AuditLog::where('action', 'payroll.report')->exists());

        // رقم الحساب من ملف الموظف يزيل التنبيه
        $e = Employee::allCompanies()->where('code', 'UHR-1007')->firstOrFail();
        $f = $this->getJson("/api/hr/employees/{$e->id}")->json('data');
        $this->putJson("/api/hr/employees/{$e->id}", ['name' => $f['name']['ar'] ?? $e->name, 'name_en' => $e->name_en, 'gender' => $e->gender, 'title_ar' => $e->title_ar, 'title_en' => $e->title_en,
            'department_id' => $e->department_id, 'location_id' => $e->location_id, 'manager_id' => $e->manager_id, 'email' => $e->email, 'phone' => $e->phone, 'national_id' => $e->national_id,
            'dob' => $e->dob?->toDateString(), 'bank_name' => 'بنك سورية الدولي', 'bank_account' => 'sy12 3456 7890'])->assertOk();
        $this->assertSame('SY1234567890', $e->refresh()->bank_account);
        $c = collect($this->getJson('/api/hr/payroll?month=2026-09')->json('checks'));
        $this->assertFalse($c->contains(fn ($x) => $x['code'] === 'no_bank' && $x['employee_id'] === $e->id));
    }
}
