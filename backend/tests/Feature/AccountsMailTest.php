<?php

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\User;
use App\Notifications\AccountInvite;
use App\Notifications\ResetPasswordNotice;
use App\Support\MailSetup;
use App\Support\Settings;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 11e: روابط التفعيل وتعيين كلمة المرور، إعداد البريد الصادر من المنصة، اسم المرسل لكل شركة، وسجل البريد */
class AccountsMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        MailSetup::forget();
    }

    protected function tearDown(): void { Carbon::setTestNow(); MailSetup::forget(); parent::tearDown(); }

    private function login(string $email, string $password = '123456'): void
    {
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    public function test_invite_validity_expiry_resend_and_forgot_password(): void
    {
        Notification::fake();
        $this->login('admin@uniqueness.sy');
        $this->putJson('/api/hr/admin/users/settings', ['invite_hours' => 0])->assertStatus(422);
        $this->putJson('/api/hr/admin/users/settings', ['invite_hours' => 24])->assertOk()->assertJsonPath('settings.invite_hours', 24);
        $r = $this->postJson('/api/hr/admin/users', ['email' => 'ops@uniqueness.sy', 'name' => 'العمليات', 'roles' => ['executive']])->assertCreated();
        $u = User::where('email', 'ops@uniqueness.sy')->first();
        $this->assertSame(now()->addHours(24)->toIso8601String(), $u->invite_expires_at->toIso8601String());
        Notification::assertSentTo($u, AccountInvite::class, fn ($n) => $n->hours === 24 && $n->url === $r->json('link'));
        $this->assertSame(1, collect($this->getJson('/api/hr/admin/users?status=pending')->json('data'))->where('email', 'ops@uniqueness.sy')->count());

        // انتهاء الصلاحية ← 410 مع expired، ثم رابط جديد من «إعادة الإرسال»
        $this->travel(25)->hours();
        $this->getJson('/api/auth/activate/'.basename($r->json('link')))->assertStatus(410)->assertJsonPath('expired', true);
        $this->assertSame('expired', collect($this->getJson('/api/hr/admin/users')->json('data'))->firstWhere('email', 'ops@uniqueness.sy')['invite']['state']);
        $link = $this->postJson("/api/hr/admin/users/{$u->id}/reset-password")->assertOk()->json('link');
        Notification::assertSentTo($u, AccountInvite::class, fn ($n) => $n->kind === 'invite' && $n->url === $link);   // لم يُفعَّل بعد → دعوة لا «إعادة تعيين»

        // «نسيت كلمة المرور» يعمل أيضاً لحساب لم يُفعَّل، ويفعّله
        $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/forgot', ['email' => 'ops@uniqueness.sy'])->assertOk();
        $token = null;
        Notification::assertSentTo($u, ResetPasswordNotice::class, function ($n) use (&$token) { $token = $n->token; return true; });
        $this->postJson('/api/auth/reset', ['token' => $token, 'email' => 'ops@uniqueness.sy', 'password' => 'Ops2026pass', 'password_confirmation' => 'Ops2026pass'])->assertOk();
        $u->refresh();
        $this->assertNotNull($u->activated_at);
        $this->assertNull($u->invite_hash);
        $this->getJson('/api/auth/activate/'.basename($link))->assertStatus(410);
        $this->postJson('/api/auth/login', ['email' => 'ops@uniqueness.sy', 'password' => 'Ops2026pass'])->assertOk();
    }

    public function test_reset_link_keeps_the_current_password_until_used(): void
    {
        Notification::fake();
        $this->login('admin@uniqueness.sy');
        $samer = User::where('email', 'samer.halabi@uniqueness.sy')->first();
        $link = $this->postJson("/api/hr/admin/users/{$samer->id}/reset-password")->assertOk()->json('link');
        Notification::assertSentTo($samer, AccountInvite::class, fn ($n) => $n->kind === 'reset');
        $this->login('samer.halabi@uniqueness.sy');                        // كلمة المرور الحالية ما زالت تعمل
        $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/activate/'.basename($link))->assertOk()->assertJsonPath('data.kind', 'reset');
        $this->postJson('/api/auth/activate/'.basename($link), ['password' => 'Samer2026new', 'password_confirmation' => 'Samer2026new'])->assertOk()->assertJsonPath('user.email', 'samer.halabi@uniqueness.sy');
        $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'samer.halabi@uniqueness.sy', 'password' => '123456'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'samer.halabi@uniqueness.sy', 'password' => 'Samer2026new'])->assertOk();
    }

    public function test_platform_smtp_settings_test_message_sender_name_and_log(): void
    {
        $this->login('hr@uniqueness.sy');
        $this->getJson('/api/platform/mail')->assertForbidden();
        $this->login('platform@atmata.sy');
        $this->putJson('/api/platform/mail', ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'from_name' => 'UHR', 'log_days' => 90])
            ->assertStatus(422)->assertJsonValidationErrors('from_address');
        $d = $this->putJson('/api/platform/mail', ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => 'S3cret',
            'from_address' => 'no-reply@atmata-group.com', 'from_name' => 'UHR', 'log_days' => 90])->assertOk()->json('data');
        $this->assertSame(['settings', true], [$d['source'], $d['password_set']]);
        $this->assertArrayNotHasKey('password', $d);
        $saved = Settings::platform('mail');
        $this->assertSame('S3cret', Crypt::decryptString($saved['password']));
        $this->assertSame(['smtp.example.com', 587, 'apikey', 'no-reply@atmata-group.com'], [config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.username'), config('mail.from.address')]);
        $this->assertSame('array', config('mail.default'));          // الاختبارات تبقى على array
        // حفظ دون كلمة مرور يبقيها
        $this->putJson('/api/platform/mail', ['host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'apikey', 'from_address' => 'no-reply@atmata-group.com', 'from_name' => 'UHR', 'log_days' => 30])->assertOk();
        $this->assertSame(['smtps', 'S3cret'], [config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.password')]);

        // رسالة تجريبية ← سجل «أُرسلت»
        $this->postJson('/api/platform/mail/test', ['to' => 'me@example.com'])->assertOk();
        $log = MailLog::latest('id')->first();
        $this->assertSame(['me@example.com', 'sent', null], [$log->to, $log->status, $log->company_id]);

        // رسالة لحساب شركة: اسم المرسل من «قنوات الإشعارات» + الرد إلى، وتظهر في سجل الشركة فقط
        $this->login('hr@uniqueness.sy');
        $this->putJson('/api/hr/admin/channels/email', ['enabled' => true, 'config' => ['from_name' => 'موارد الفرادة البشرية', 'reply_to' => 'hr@uniqueness.sy']])->assertOk();
        $this->login('admin@uniqueness.sy');
        $samer = User::where('email', 'samer.halabi@uniqueness.sy')->first();
        $this->postJson("/api/hr/admin/users/{$samer->id}/reset-password")->assertOk();
        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();
        $this->assertSame(['موارد الفرادة البشرية', 'no-reply@atmata-group.com', 'hr@uniqueness.sy'], [$sent->getFrom()[0]->getName(), $sent->getFrom()[0]->getAddress(), $sent->getReplyTo()[0]->getAddress()]);
        $this->assertStringContainsString('/activate/', $sent->getHtmlBody());
        $mine = $this->getJson('/api/hr/admin/mail-log')->assertOk()->json('data');
        $this->assertSame(['samer.halabi@uniqueness.sy', 'sent', 'AccountInvite'], [$mine[0]['to'], $mine[0]['status'], $mine[0]['kind']]);
        $this->assertNotContains('me@example.com', array_column($mine, 'to'));
        $this->login('platform@atmata.sy');
        $this->assertGreaterThanOrEqual(2, $this->getJson('/api/platform/mail/log')->assertOk()->json('meta.total'));
    }

    public function test_failed_delivery_is_reported_and_logged(): void
    {
        $this->login('platform@atmata.sy');
        $this->putJson('/api/platform/mail', ['host' => '127.0.0.1', 'port' => 9, 'encryption' => 'none', 'from_address' => 'no-reply@atmata-group.com', 'from_name' => 'UHR', 'log_days' => 90])->assertOk();
        config(['mail.default' => 'smtp']);
        $this->postJson('/api/platform/mail/test', ['to' => 'me@example.com'])->assertStatus(422)->assertJsonPath('code', 'mail_failed');
        $this->assertSame('failed', MailLog::latest('id')->value('status'));
        $this->assertSame(1, $this->getJson('/api/platform/mail/log?status=failed')->json('meta.total'));
    }
}
