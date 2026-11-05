<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\JobOpening;
use App\Models\LeaveRequest;
use App\Models\MonthSheet;
use App\Models\User;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ملاحظة الاختبار 77: عزل البيانات والصلاحيات بين شركتين — مدير الموارد البشرية في «أتمتة» (admin + hr_director)
 * لا يرى ولا يعدّل أي سجل من «الفرادة» بأي رقم معرّف، ولا تظهر بياناتها في قوائمه أو بحثه أو تصديره.
 * + بوابة الشركة (86): الدخول من بوابة شركة لموظفيها فقط، وتفاصيل الشركات في لوحة المنصة بلا كلمات مرور (47/48).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $this->postJson('/api/auth/logout');
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'] + $extra);
    }

    public function test_one_company_cannot_reach_another_companys_records(): void
    {
        $fa = Company::where('slug', 'alfarada')->firstOrFail();
        // معرّفات من «الفرادة»
        [$emp, $cand, $job, $asset, $leave, $dept, $file, $sheet] = Tenant::run($fa->id, function () {
            $e = Employee::where('email', 'samer.halabi@alfarada.sy')->firstOrFail();
            $hr = User::where('email', 'hr@alfarada.sy')->firstOrFail();
            $doc = app(\App\Services\People\EmployeeDocs::class)->upload($e, UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'), ['type' => 'id'], $hr);
            $sheet = app(\App\Services\Payroll\Payroll::class)->open('2026-09', $hr);
            return [$e, Candidate::first(), JobOpening::first(), Asset::first(), LeaveRequest::first(), Department::first(), $doc->file_id, $sheet];
        });

        $this->login('hr@atmata.sy')->assertOk();
        $this->unlockSalaries();
        $deny = fn ($r) => $this->assertContains($r->status(), [403, 404], $r->baseResponse->getContent() ? substr($r->baseResponse->getContent(), 0, 200) : '');

        // قراءة بالمعرّف
        foreach ([
            "/api/hr/employees/{$emp->id}", "/api/hr/employees/{$emp->id}/linkable", "/api/hr/candidates/{$cand->id}", "/api/hr/jobs/{$job->id}", "/api/hr/jobs/{$job->id}/cv-matches",
            "/api/hr/custody/{$asset->id}", "/api/hr/onboarding/{$emp->id}", "/api/hr/payroll/{$sheet->id}/export", "/api/hr/payroll/{$sheet->id}/report/bank",
            "/api/files/{$file}",
        ] as $url) $deny($this->get($url, ['Accept' => 'application/json']));

        // كتابة بالمعرّف
        $deny($this->putJson("/api/hr/employees/{$emp->id}", ['name' => 'x']));
        $deny($this->postJson("/api/hr/employees/{$emp->id}/documents/link", ['file_id' => $file]));
        $deny($this->post("/api/hr/employees/{$emp->id}/biometrics", ['signature' => 'data:image/png;base64,AAAA'], ['Accept' => 'application/json']));
        $deny($this->postJson("/api/hr/candidates/{$cand->id}/move", ['stage' => 'rejected', 'reason' => 'محاولة من شركة أخرى']));
        $deny($this->postJson("/api/hr/custody/{$asset->id}/issue", ['employee_id' => $emp->id]));
        $deny($this->postJson("/api/hr/payroll/{$sheet->id}/approve"));
        $deny($this->postJson("/api/hr/payroll/{$sheet->id}/pay", ['currency' => 'SYP', 'pay_date' => '2026-09-30']));
        $deny($this->putJson("/api/hr/admin/departments/{$dept->id}", ['code' => 'xx', 'name_ar' => 'x', 'name_en' => 'x']));
        $deny($this->postJson("/api/hr/admin/users/{$emp->id}/deactivate"));

        // القوائم والبحث والتقارير لا تحمل بيانات الشركة الأخرى
        $ids = collect($this->getJson('/api/hr/employees?status=all')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($emp->id));
        $this->assertSame([], $this->getJson('/api/hr/search?q='.urlencode('سامر'))->assertOk()->json('employees'));
        $this->assertSame([], collect($this->getJson('/api/hr/search?q='.urlencode($asset->tag))->json('records'))->where('kind', 'asset')->values()->all());
        $this->assertNull($this->getJson('/api/hr/payroll?month=2026-09')->assertOk()->json('sheet'));
        $this->assertFalse(collect($this->getJson('/api/hr/candidates')->json('data'))->pluck('id')->contains($cand->id));
        $this->assertFalse(collect($this->getJson('/api/hr/leaves?tab=all')->json('data'))->pluck('id')->contains($leave->id));

        // ولا يغيّر شيئاً فعلاً
        $this->assertSame('active', Tenant::run($fa->id, fn () => $emp->fresh()->status));
        $this->assertSame('calculated', $sheet->fresh()->status);
    }

    public function test_company_portal_login_and_platform_company_details(): void
    {
        // من بوابة «أتمتة» لا يدخل موظف «الفرادة» — نفس رسالة الخطأ
        $this->login('samer.halabi@alfarada.sy', ['company' => 'atmata'])->assertUnprocessable()->assertJsonPath('code', 'invalid');
        $this->login('samer.halabi@alfarada.sy', ['company' => 'alfarada'])->assertOk();
        $this->login('leen.ali@mail.sy', ['company' => 'atmata'])->assertOk();   // المتقدم بحسابه العام

        // نطاق الشركة الخاص والعناوين الفرعية
        Company::where('slug', 'alfarada')->update(['domain' => 'hr.alfarada.sy', 'brand_color' => '#0f766e']);
        $this->postJson('/api/auth/logout');
        $this->getJson('http://hr.alfarada.sy/api/public/context')->assertOk()->assertJsonPath('company.slug', 'alfarada')->assertJsonPath('company.brand_color', '#0f766e');
        $this->getJson('http://localhost/api/public/context')->assertOk()->assertJsonPath('company', null);
        Settings::putPlatform('portal_hosts', ['base_domain' => 'uhr.example.com']);
        $this->getJson('http://atmata.uhr.example.com/api/public/context')->assertJsonPath('company.slug', 'atmata');
        $this->getJson('http://www.uhr.example.com/api/public/context')->assertJsonPath('company', null);
        $this->postJson('http://hr.alfarada.sy/api/auth/login', ['email' => 'hr@atmata.sy', 'password' => '123456'])->assertUnprocessable();

        // لوحة المنصة: الاستخدام والحسابات بلا كلمات مرور، وإجراءات آمنة
        $this->login('platform@atmata.sy')->assertOk();
        $fa = Company::where('slug', 'alfarada')->firstOrFail();
        $d = $this->getJson("/api/platform/companies/{$fa->id}/detail")->assertOk();
        $this->assertGreaterThan(0, $d->json('data.usage.users.total'));
        $this->assertContains('https://hr.alfarada.sy', $d->json('data.portal_urls'));
        $keys = collect($d->json('data.accounts'))->map(fn ($a) => array_keys($a))->flatten()->unique()->all();
        $this->assertEmpty(array_intersect($keys, ['password', 'remember_token', 'invite_hash', 'totp_secret', 'link']));
        $this->assertStringNotContainsString('$2y$', $d->getContent());
        $samer = collect($d->json('data.accounts'))->firstWhere('email', 'samer.halabi@alfarada.sy');
        $this->postJson("/api/platform/companies/{$fa->id}/users/{$samer['id']}/deactivate")->assertOk()->assertJsonPath('data.state', 'inactive');
        $this->postJson("/api/platform/companies/{$fa->id}/users/{$samer['id']}/activate")->assertOk()->assertJsonPath('data.state', 'active');
        $this->postJson("/api/platform/companies/{$fa->id}/users/{$samer['id']}/reset-password")->assertOk()->assertJsonPath('sent', true)->assertJsonMissingPath('link');
        $other = Company::where('slug', 'atmata')->value('id');
        $this->postJson("/api/platform/companies/{$other}/users/{$samer['id']}/unlock")->assertNotFound();   // حساب ليس من هذه الشركة
        $this->login('hr@alfarada.sy');
        $this->getJson("/api/platform/companies/{$fa->id}/detail")->assertForbidden();
    }
}
