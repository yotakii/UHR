<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotice;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, string $pw = '123456', string $lang = 'ar')
    {
        return $this->withHeader('Accept-Language', $lang)->postJson('/api/auth/login', ['email' => $email, 'password' => $pw]);
    }

    public function test_guest_me_is_null(): void
    {
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user', null);
    }

    public function test_each_role_lands_on_its_portal(): void
    {
        foreach (['admin@alfarada.sy' => 'hr', 'hr@alfarada.sy' => 'hr', 'basel.atassi@alfarada.sy' => 'hr', 'samer.halabi@alfarada.sy' => 'employee',
            'alaa.sheikhani@alfarada.sy' => 'employee', 'leen.ali@mail.sy' => 'candidate', 'platform@atmata.sy' => 'platform', 'jobs@atmata.sy' => 'hr'] as $email => $portal) {
            $this->login(strtoupper($email))->assertOk()->assertJsonPath('user.portal', $portal)->assertJsonPath('user.email', $email);
            $this->getJson('/api/auth/me')->assertJsonPath('user.portal', $portal);
            $this->postJson('/api/auth/logout')->assertOk();
            $this->assertGuest();
        }
    }

    public function test_session_payload_for_employee(): void
    {
        $this->login('hr@alfarada.sy')->assertOk()
            ->assertJsonPath('user.name.ar', 'رشا الخطيب')
            ->assertJsonPath('user.name.en', 'Rasha Al-Khatib')
            ->assertJsonPath('user.employee.code', 'UHR-1003')
            ->assertJsonPath('user.employee.dept.en', 'Human Resources')
            ->assertJsonPath('user.must_change_password', false);
        $this->assertContains('hr_director', $this->getJson('/api/auth/me')->json('user.roles'));
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
    }

    public function test_wrong_password_is_generic_and_bilingual(): void
    {
        $this->login('nobody@alfarada.sy')->assertStatus(422)->assertJsonPath('code', 'invalid');
        $this->login('samer.halabi@alfarada.sy', 'bad')->assertStatus(422)->assertJsonPath('remaining', 4)
            ->assertJsonPath('message', 'البريد أو كلمة المرور غير صحيحة. تحقق منهما وحاول مجدداً.');
        $this->login('samer.halabi@alfarada.sy', 'bad', 'en')->assertJsonPath('message', 'Email or password is incorrect. Check them and try again.');
        $this->postJson('/api/auth/login', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_five_failures_lock_the_account_then_it_unlocks(): void
    {
        foreach (range(1, 4) as $i) $this->login('samer.halabi@alfarada.sy', 'bad')->assertStatus(422);
        $this->login('samer.halabi@alfarada.sy', 'bad')->assertStatus(423)->assertJsonPath('code', 'locked')->assertJsonPath('minutes', 5);
        $this->login('samer.halabi@alfarada.sy')->assertStatus(423);              // حتى كلمة المرور الصحيحة
        $this->assertSame(1, AuditLog::where('action', 'auth.locked')->count());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->login('samer.halabi@alfarada.sy')->assertOk();
        $u = User::where('email', 'samer.halabi@alfarada.sy')->first();
        $this->assertSame(0, $u->failed_logins);
        $this->assertNull($u->locked_until);
    }

    public function test_terminated_employee_and_disabled_account_cannot_sign_in(): void
    {
        Employee::where('code', 'UHR-1028')->update(['status' => 'terminated']);
        $this->login('hussam.bitar@alfarada.sy')->assertStatus(403)->assertJsonPath('code', 'blocked');
        User::where('email', 'samer.halabi@alfarada.sy')->update(['active' => false]);
        $this->login('samer.halabi@alfarada.sy')->assertStatus(403);
        $this->assertGuest();
    }

    public function test_temporary_password_must_be_changed_first(): void
    {
        $this->artisan('uhr:install', ['--email' => 'owner@company.sy'])->assertSuccessful();
        $u = User::where('email', 'owner@company.sy')->first();
        $u->forceFill(['password' => 'Temp1234x'])->save();

        $this->login('owner@company.sy', 'Temp1234x')->assertOk()->assertJsonPath('user.must_change_password', true);
        \Illuminate\Support\Facades\Route::middleware(['api', 'auth', 'fresh'])->get('/api/_probe', fn () => ['ok' => true]);
        $this->getJson('/api/_probe')->assertStatus(403)->assertJsonPath('code', 'password_change_required');

        $this->postJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'NewPass2026', 'password_confirmation' => 'NewPass2026'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/auth/password', ['current_password' => 'Temp1234x', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/password', ['current_password' => 'Temp1234x', 'password' => 'NewPass2026', 'password_confirmation' => 'NewPass2026'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/_probe')->assertOk();
    }

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/forgot', ['email' => 'nobody@x.sy'])->assertOk();
        Notification::assertNothingSent();

        $msg = $this->postJson('/api/auth/forgot', ['email' => 'samer.halabi@alfarada.sy'])->assertOk()->json('message');
        $this->assertSame($msg, $this->postJson('/api/auth/forgot', ['email' => 'ghost@x.sy'])->json('message'), 'same reply either way');
        $u = User::where('email', 'samer.halabi@alfarada.sy')->first();
        $token = null;
        Notification::assertSentTo($u, ResetPasswordNotice::class, function ($n) use (&$token, $u) {
            $token = $n->token;
            return str_starts_with($n->url($u), 'http://localhost:5180/reset?token=') && str_contains($n->toMail($u)->render(), 'إعادة تعيين كلمة المرور');
        });

        $u->forceFill(['failed_logins' => 3, 'locked_until' => now()->addMinutes(5)])->save();
        $this->postJson('/api/auth/reset', ['email' => $u->email, 'token' => 'bad', 'password' => 'Fresh2026x', 'password_confirmation' => 'Fresh2026x'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_token');
        $this->postJson('/api/auth/reset', ['email' => $u->email, 'token' => $token, 'password' => 'Fresh2026x', 'password_confirmation' => 'Fresh2026x'])->assertOk();
        $this->login($u->email, '123456')->assertStatus(422);
        $this->login($u->email, 'Fresh2026x')->assertOk();
    }

    public function test_portal_middleware(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'auth', 'fresh', 'portal:hr'])->get('/api/_hr', fn () => ['ok' => true]);
        $this->getJson('/api/_hr')->assertStatus(401);
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/_hr')->assertStatus(403);
        $this->postJson('/api/auth/logout');
        $this->login('hr@alfarada.sy');
        $this->getJson('/api/_hr')->assertOk();
    }
}
