<?php

namespace Tests\Feature;

use App\Models\PushDevice;
use App\Models\User;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** تطبيق الجوال: البقاء مسجّلاً، تسجيل جهاز الإشعارات، وإرسال الإشعار الشخصي عبر Firebase */
class MobileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function serviceAccount(): string
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $pem);
        return json_encode(['type' => 'service_account', 'project_id' => 'uhr-test', 'client_email' => 'push@uhr-test.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    }

    public function test_remember_me_and_device_registration(): void
    {
        $r = $this->postJson('/api/auth/login', ['email' => 'samer.halabi@uniqueness.sy', 'password' => '123456', 'remember' => true])->assertOk();
        $this->assertTrue(collect($r->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
        $this->postJson('/api/me/push', ['token' => str_repeat('a', 40), 'app_version' => '1.0.0'])->assertOk()->assertJsonPath('enabled', false);
        $this->postJson('/api/me/push', ['token' => str_repeat('a', 40)])->assertOk();   // نفس الجهاز مرة واحدة
        $this->assertSame(1, PushDevice::count());
        $this->deleteJson('/api/me/push', ['token' => str_repeat('a', 40)])->assertOk();
        $this->assertSame(0, PushDevice::count());
    }

    public function test_platform_key_and_personal_notice_reaches_the_phone(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'platform@atmata.sy', 'password' => '123456'])->assertOk();
        $this->putJson('/api/platform/push', ['enabled' => true, 'credentials' => '{"type":"user"}'])->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->putJson('/api/platform/push', ['enabled' => true, 'credentials' => $this->serviceAccount()])->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.project_id', 'uhr-test');
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(Settings::platform('push')));   // محفوظ مشفّراً

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'projects/uhr-test/messages/1'])->push(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);
        $samer = User::where('email', 'samer.halabi@uniqueness.sy')->first();
        PushDevice::create(['user_id' => $samer->id, 'company_id' => $samer->company_id, 'token' => str_repeat('b', 40)]);
        PushDevice::create(['user_id' => $samer->id, 'company_id' => $samer->company_id, 'token' => str_repeat('c', 40)]);

        Tenant::run($samer->company_id, fn () => Notify::user($samer->id, 'تمت الموافقة على إجازتك', 'Your leave was approved', 'requests'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'fcm.googleapis.com') && $req['message']['notification']['body'] === 'تمت الموافقة على إجازتك'
            && $req['message']['data']['link'] === 'requests' && $req->hasHeader('Authorization', 'Bearer tok'));
        $this->assertSame(1, PushDevice::where('user_id', $samer->id)->count());   // الرمز المنتهي حُذف

        // إشعارات الموارد البشرية العامة لا تُرسل للجوال
        Http::fake(['*' => Http::response([])]);
        Tenant::run($samer->company_id, fn () => Notify::hr('إشعار عام', 'General', 'leaves'));
        Http::assertNothingSent();
    }
}
