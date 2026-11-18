<?php

namespace Tests\Feature;

use App\Support\Settings;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الموظف الداخل من البوابة العامة يُنقل لعنوان بوابة شركته برابط تسليم لمرة واحدة (FEATURES 05b) */
class HandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function out(): void { $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards(); }

    private function login(string $email, array $extra = [], string $base = 'http://localhost'): \Illuminate\Testing\TestResponse
    {
        $this->out();
        return $this->postJson($base.'/api/auth/login', ['email' => $email, 'password' => '123456'] + $extra)->assertOk();
    }

    public function test_employee_is_handed_to_the_company_portal(): void
    {
        // بلا عنوان مستقل للشركة: لا تحويل
        $this->login('hr@uniqueness.sy', ['handoff' => true])->assertJsonMissingPath('redirect');

        Settings::putPlatform('portal_hosts', ['base_domain' => 'uhr.test', 'redirect_employees' => true]);
        $r = $this->login('hr@uniqueness.sy', ['handoff' => true])->assertJsonPath('user.email', 'hr@uniqueness.sy');
        $url = $r->json('redirect');
        $this->assertMatchesRegularExpression('#^https://uniqueness\.uhr\.test/handoff/[A-Za-z0-9]{48}$#', $url);
        $token = basename($url);

        // دون طلب التحويل (رابط after / تطبيق الجوال) أو للمتقدم بحسابه العام: لا تحويل
        $this->login('hr@uniqueness.sy')->assertJsonMissingPath('redirect');
        $this->login('leen.ali@mail.sy', ['handoff' => true])->assertJsonMissingPath('redirect');
        // الدخول من عنوان الشركة نفسه: لا تحويل
        $this->login('hr@uniqueness.sy', ['handoff' => true], 'http://uniqueness.uhr.test')->assertJsonMissingPath('redirect');

        // عنوان شركة أخرى يرفض الرابط ويستهلكه
        $this->out();
        $this->postJson('http://atmata.uhr.test/api/auth/handoff', ['token' => $token])->assertStatus(410)->assertJsonPath('code', 'handoff_invalid');
        $this->postJson('http://uniqueness.uhr.test/api/auth/handoff', ['token' => $token])->assertStatus(410);

        // رابط جديد يفتح الجلسة على عنوان الشركة مرة واحدة فقط
        $token = basename($this->login('hr@uniqueness.sy', ['handoff' => true])->json('redirect'));
        $this->out();
        $this->postJson('http://uniqueness.uhr.test/api/auth/handoff', ['token' => $token])->assertOk()->assertJsonPath('user.email', 'hr@uniqueness.sy');
        $this->getJson('http://uniqueness.uhr.test/api/auth/me')->assertJsonPath('user.email', 'hr@uniqueness.sy');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.handoff']);
        $this->out();
        $this->postJson('http://uniqueness.uhr.test/api/auth/handoff', ['token' => $token])->assertStatus(410);

        // المنصة توقف التحويل
        Settings::putPlatform('portal_hosts', ['base_domain' => 'uhr.test', 'redirect_employees' => false]);
        $this->login('hr@uniqueness.sy', ['handoff' => true])->assertJsonMissingPath('redirect');
    }
}
