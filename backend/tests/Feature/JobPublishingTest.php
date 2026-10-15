<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobOpening;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** نشر الشواغر من نظام الشركة: مكان النشر، مراجعة المنصة، الإغلاق (SRS §10.15) */
class JobPublishingTest extends TestCase
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
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function job(array $over = []): array
    {
        $lk = $this->getJson('/api/hr/jobs/lookups')->json();
        $dept = $lk['departments'][0]['id'];
        return array_replace([
            'title_ar' => 'محلل بيانات', 'title_en' => 'Data Analyst', 'department_id' => $dept, 'location_id' => $lk['locations'][0]['id'], 'employment_type' => 'full_time',
            'openings' => 1, 'min_years' => 2, 'degree' => 2, 'salary_min' => 700, 'salary_max' => 1000, 'currency' => 'USD',
            'summary_ar' => 'تحليل بيانات المبيعات وبناء التقارير.', 'summary_en' => 'Analyse sales data and build reports.',
            'requirements' => [['ar' => 'SQL وExcel', 'en' => 'SQL and Excel']], 'visibility' => 'public', 'closes_at' => '2026-11-30',
            'hard_to_fill' => false, 'requires_photo' => false, 'status' => 'open',
        ], $over);
    }

    public function test_only_job_roles_of_the_company_manage_jobs(): void
    {
        $this->getJson('/api/hr/jobs')->assertUnauthorized();
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/jobs')->assertForbidden();
        $this->postJson('/api/auth/logout');
        $this->login('leen.ali@mail.sy');
        $this->getJson('/api/hr/jobs')->assertForbidden();
        $this->postJson('/api/auth/logout');
        $this->login('jobs@atmata.sy');   // مسؤول توظيف
        $list = $this->getJson('/api/hr/jobs')->assertOk()->assertJsonPath('company.slug', 'atmata');
        $this->assertSame(['ATM-2026-001', 'ATM-2026-002', 'ATM-2026-003'], collect($list->json('data'))->pluck('code')->sort()->values()->all());
    }

    public function test_hr_list_shows_pipeline_counts(): void
    {
        $this->login('hr@alfarada.sy');
        $j = collect($this->getJson('/api/hr/jobs')->json('data'))->firstWhere('code', 'REQ-2026-034');
        $this->assertSame(['new' => 1, 'offer' => 1], array_filter($j['pipeline']));
        $this->assertSame(['public', 'approved'], [$j['visibility'], $j['portal_status']]);
    }

    public function test_client_job_waits_for_platform_review_then_goes_live(): void
    {
        $this->login('hr@alfarada.sy');
        $res = $this->postJson('/api/hr/jobs', $this->job())->assertCreated()->assertJsonPath('data.portal_status', 'pending')->assertJsonPath('data.status', 'open');
        $code = $res->json('data.code');
        $this->assertMatchesRegularExpression('/^REQ-2026-\d{3}$/', $code);
        $this->getJson("/api/public/jobs/alfarada/{$code}")->assertOk();             // صفحة الشركة فوراً
        $this->assertFalse(collect($this->getJson('/api/public/jobs')->json('data'))->contains('code', $code));   // البوابة بعد المراجعة
        $this->postJson('/api/auth/logout');

        $this->login('platform@atmata.sy');
        $pending = collect($this->getJson('/api/platform/reviews')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['REQ-2026-041', $code], $pending->pluck('code')->all());
        $id = $pending->firstWhere('code', $code)['id'];
        $this->postJson("/api/platform/reviews/{$id}", ['decision' => 'rejected', 'reason' => 'قصير'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/platform/reviews/{$id}", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.portal_status', 'approved');
        $this->assertTrue(collect($this->getJson('/api/public/jobs')->json('data'))->contains('code', $code));
        $this->assertTrue(\App\Models\Notice::allCompanies()->where('company_id', Company::where('slug', 'alfarada')->value('id'))->where('text_ar', 'نُشر الشاغر «محلل بيانات» على بوابة فرص العمل')->exists());
        $this->postJson('/api/auth/logout');

        // تعديل المحتوى يعيده للمراجعة
        $this->login('hr@alfarada.sy');
        $this->putJson("/api/hr/jobs/{$id}", $this->job(['title_ar' => 'محلل بيانات أول', 'title_en' => 'Senior Data Analyst']))->assertOk()->assertJsonPath('data.portal_status', 'pending');
        $this->putJson("/api/hr/jobs/{$id}", $this->job(['title_ar' => 'محلل بيانات أول', 'title_en' => 'Senior Data Analyst', 'closes_at' => '2026-12-15']))->assertOk()->assertJsonPath('data.portal_status', 'pending');
    }

    public function test_rejection_reason_reaches_the_company(): void
    {
        $this->login('platform@atmata.sy');
        $id = JobOpening::allCompanies()->where('code', 'REQ-2026-041')->value('id');
        $this->postJson("/api/platform/reviews/{$id}", ['decision' => 'rejected', 'reason' => 'أضف نطاق الراتب وموقع العمل بدقة.'])->assertOk();
        $this->postJson('/api/auth/logout');
        $this->login('hr@alfarada.sy');
        $j = collect($this->getJson('/api/hr/jobs')->json('data'))->firstWhere('code', 'REQ-2026-041');
        $this->assertSame(['rejected', 'أضف نطاق الراتب وموقع العمل بدقة.'], [$j['portal_status'], $j['portal_note']]);
        // إعادة الحفظ ترجعه للمراجعة
        $this->putJson("/api/hr/jobs/{$j['id']}", $this->job(['title_ar' => 'أخصائي موارد بشرية', 'title_en' => 'HR Generalist']))->assertJsonPath('data.portal_status', 'pending');
    }

    public function test_platform_owner_publishes_without_review(): void
    {
        $this->login('hr@atmata.sy');
        $this->postJson('/api/hr/jobs', $this->job())->assertCreated()->assertJsonPath('data.portal_status', 'approved');
        $this->assertCount(4, $this->getJson('/api/public/jobs?company=atmata')->json('data'));
    }

    public function test_visibility_internal_company_and_disabled_portal(): void
    {
        $this->login('hr@alfarada.sy');
        $this->postJson('/api/hr/jobs', $this->job(['visibility' => 'internal']))->assertCreated()->assertJsonPath('data.portal_status', 'none');
        $c = $this->postJson('/api/hr/jobs', $this->job(['visibility' => 'company']))->assertCreated()->assertJsonPath('data.portal_status', 'none')->json('data.code');
        $this->getJson("/api/public/jobs/alfarada/{$c}")->assertOk();
        Company::where('slug', 'alfarada')->update(['portal_enabled' => false]);
        $this->postJson('/api/hr/jobs', $this->job())->assertStatus(422)->assertJsonPath('code', 'portal_disabled');
    }

    public function test_validation_and_close(): void
    {
        $this->login('hr@alfarada.sy');
        $this->postJson('/api/hr/jobs', $this->job(['title_ar' => '', 'salary_max' => 100, 'requirements' => [], 'closes_at' => '2026-01-01']))->assertStatus(422)
            ->assertJsonValidationErrors(['title_ar', 'salary_max', 'requirements', 'closes_at']);
        // إدارة من شركة أخرى غير مقبولة
        $atmDept = \App\Models\Department::allCompanies()->where('code', 'eng')->value('id');
        $this->postJson('/api/hr/jobs', $this->job(['department_id' => $atmDept]))->assertStatus(422)->assertJsonValidationErrors('department_id');

        $id = JobOpening::where('code', 'REQ-2026-031')->value('id');
        $this->postJson("/api/hr/jobs/{$id}/status", ['status' => 'closed'])->assertOk()->assertJsonPath('data.accepting', false);
        $this->getJson('/api/public/jobs/alfarada/REQ-2026-031')->assertNotFound();
    }
}
