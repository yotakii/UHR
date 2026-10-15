<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobOpening;
use App\Models\User;
use App\Notifications\CompanyWelcome;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** مدير المنصة: الشركات، النصوص، الإحصاءات — والعزل بين الشركات */
class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Storage::fake('public');
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function asPlatform(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'platform@atmata.sy', 'password' => '123456'])->assertOk()->assertJsonPath('user.portal', 'platform');
    }

    public function test_only_platform_admin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'hr@alfarada.sy', 'password' => '123456']);
        $this->getJson('/api/platform/companies')->assertForbidden();
    }

    public function test_overview_and_companies(): void
    {
        $this->asPlatform();
        $this->getJson('/api/platform/overview')->assertOk()->assertJsonPath('companies', 2)->assertJsonPath('portal_jobs', 8)->assertJsonPath('pending', 1)->assertJsonPath('applications', 10);
        $list = collect($this->getJson('/api/platform/companies')->json('data'));
        $this->assertSame(['jobs_open' => 6, 'jobs_portal' => 5, 'jobs_pending' => 1, 'applications' => 10, 'employees' => 32], $list->firstWhere('slug', 'alfarada')['counts']);
        $this->assertArrayNotHasKey('candidates', $list->first());
    }

    public function test_create_company_with_admin_and_logo(): void
    {
        Notification::fake();
        $this->asPlatform();
        $this->post('/api/platform/companies', ['slug' => 'Bad Slug', 'name_ar' => '', 'name_en' => 'X'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['slug', 'name_ar', 'admin_email']);
        $this->post('/api/platform/companies', ['slug' => 'alfarada', 'name_ar' => 'x', 'name_en' => 'x', 'admin_email' => 'n@n.sy'], ['Accept' => 'application/json'])->assertJsonValidationErrors('slug');

        $res = $this->post('/api/platform/companies', [
            'slug' => 'sham-cables', 'name_ar' => 'الشام للكابلات', 'name_en' => 'Sham Cables', 'about_ar' => 'تصنيع كابلات', 'portal_enabled' => 1, 'portal_review' => 1,
            'admin_email' => 'Admin@ShamCables.sy', 'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.slug', 'sham-cables')->assertJsonPath('admin.email', 'admin@shamcables.sy');
        $this->assertNotEmpty($res->json('admin.temporary_password'));
        $co = Company::where('slug', 'sham-cables')->first();
        Storage::disk('public')->assertExists($co->logo_path);
        $admin = User::where('email', 'admin@shamcables.sy')->first();
        $this->assertSame([$co->id, true, 'hr'], [$admin->company_id, $admin->must_change_password, $admin->portal()]);
        Notification::assertSentTo($admin, CompanyWelcome::class);
        $this->postJson('/api/auth/logout');

        // مدير الشركة الجديدة: كلمة مؤقتة ← تغيير ← ينشر شاغراً ينتظر المراجعة
        $this->postJson('/api/auth/login', ['email' => 'admin@shamcables.sy', 'password' => $res->json('admin.temporary_password')])->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/hr/jobs')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->postJson('/api/auth/password', ['current_password' => $res->json('admin.temporary_password'), 'password' => 'Sham2026pw', 'password_confirmation' => 'Sham2026pw'])->assertOk();
        $this->getJson('/api/hr/jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->assertCount(0, $this->getJson('/api/hr/jobs/lookups')->json('departments'), 'new company starts with no departments');
    }

    public function test_suspend_company_blocks_its_users_and_jobs(): void
    {
        $this->asPlatform();
        $alf = Company::where('slug', 'alfarada')->value('id');
        $this->postJson("/api/platform/companies/{$alf}/status", ['status' => 'suspended'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/platform/companies/{$alf}/status", ['status' => 'suspended', 'reason' => 'انتهاء الاشتراك السنوي'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $atm = Company::where('slug', 'atmata')->value('id');
        $this->postJson("/api/platform/companies/{$atm}/status", ['status' => 'suspended', 'reason' => 'اختبار التعليق للمالك'])->assertStatus(409);
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'hr@alfarada.sy', 'password' => '123456'])->assertForbidden()->assertJsonPath('code', 'blocked');
        $this->assertSame(['atmata'], collect($this->getJson('/api/public/jobs')->json('data'))->pluck('company.slug')->unique()->values()->all());
    }

    public function test_portal_texts(): void
    {
        $this->asPlatform();
        $this->putJson('/api/platform/portal', ['brand_latin' => 'UHR JOBS', 'hero_title_ar' => 'وظيفتك القادمة هنا', 'hero_title_en' => 'Your next job is here', 'hero_text_ar' => 'نص', 'hero_text_en' => 'Text'])->assertOk();
        $this->getJson('/api/public/portal')->assertJsonPath('brand_latin', 'UHR JOBS')->assertJsonPath('hero.title.ar', 'وظيفتك القادمة هنا');
    }

    public function test_company_users_see_only_their_company(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'hr@atmata.sy', 'password' => '123456']);
        $codes = collect($this->getJson('/api/hr/jobs')->json('data'))->pluck('code');
        $this->assertTrue($codes->every(fn ($c) => str_starts_with($c, 'ATM-')));
        $alfJob = JobOpening::allCompanies()->where('code', 'REQ-2026-031')->value('id');
        $this->getJson("/api/hr/jobs/{$alfJob}")->assertNotFound();
        $this->putJson("/api/hr/jobs/{$alfJob}", [])->assertStatus(422);   // التحقق أولاً…
        $this->postJson("/api/hr/jobs/{$alfJob}/status", ['status' => 'closed'])->assertNotFound();   // …ثم لا يجده
        $this->assertSame('open', JobOpening::allCompanies()->find($alfJob)->status);
    }
}
