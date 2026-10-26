<?php

namespace Tests\Feature;

use App\Models\MfaFactor;
use App\Models\User;
use App\Notifications\SalaryCode;
use App\Services\Payroll\Payroll;
use App\Services\Security\SalaryVault;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** حماية الرواتب بخطوتين (SRS §4.4): القفل على الخادم، TOTP، رموز الطوارئ، الرمز المرسل، مفتاح المرور، الإيقاف والإعدادات */
class SalaryVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        // الكاش (Redis في Docker) يبقى بين الاختبارات: نصفّر محاولات ورموز الحسابات نفسها
        foreach (User::pluck('id') as $id) {
            foreach (['fail', 'otp'] as $k) Cache::forget("vault:{$k}:{$id}");
            foreach (['totp-enrol', 'totp-confirm', 'totp-verify', 'backup-verify', 'otp-send', 'otp-verify', 'passkey-options', 'passkey-register', 'passkey-verify', 'reset'] as $r) RateLimiter::clear('mfa-'.$r.sha1((string) $id));
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): User
    {
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
        return User::where('email', $email)->firstOrFail();
    }

    /** يربط تطبيق المصادقة ويعيد [السر، رموز الطوارئ] */
    private function enrol(): array
    {
        $secret = $this->postJson('/api/me/mfa/totp/enrol')->assertOk()->json('data.secret');
        $r = $this->postJson('/api/me/mfa/totp/confirm', ['code' => SalaryVault::totp($secret, SalaryVault::step())])->assertOk()->assertJsonPath('data.unlocked', true);
        return [$secret, $r->json('data.backup')];
    }

    public function test_totp_matches_rfc6238_vectors(): void
    {
        $secret = SalaryVault::b32encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', SalaryVault::totp($secret, intdiv(59, 30)));
        $this->assertSame('081804', SalaryVault::totp($secret, intdiv(1111111109, 30)));
        $this->assertSame('005924', SalaryVault::totp($secret, intdiv(1234567890, 30)));
        $this->assertSame('12345678901234567890', SalaryVault::b32decode($secret));
    }

    public function test_salaries_are_masked_until_verified_and_lock_again(): void
    {
        $this->login('hr@alfarada.sy');
        $month = Payroll::currentMonth();
        $this->postJson('/api/hr/payroll', ['month' => $month])->assertCreated();
        $p = $this->getJson("/api/hr/payroll?month={$month}")->assertOk()->assertJsonPath('masked', true)->assertJsonPath('sheet.totals.net', null);
        $this->assertNull($p->json('rows.0.money'));
        $this->assertNotNull($p->json('rows.0.data'));
        $sheet = $p->json('sheet.id');
        $this->get("/api/hr/payroll/{$sheet}/export")->assertStatus(403)->assertJsonPath('code', 'salary_locked');
        $emp = $p->json('rows.0.employee.id');
        $this->getJson("/api/hr/employees/{$emp}")->assertOk()->assertJsonPath('data.salary', null)->assertJsonPath('data.can.salary', true);
        $this->getJson('/api/hr/analytics')->assertOk()->assertJsonPath('pay_masked', true)->assertJsonPath('pay', null);

        $this->getJson('/api/me/mfa')->assertOk()->assertJsonPath('data.enrolled', false)->assertJsonPath('data.unlocked', false);
        [, $codes] = $this->enrol();
        $this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', true);   // التحقق وحده لا يكفي لرواتب الآخرين (10b)
        $this->unlockSalaries();
        $this->assertNotNull($this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', false)->json('rows.0.money.net'));
        $this->assertIsFloat($this->getJson("/api/hr/employees/{$emp}")->json('data.salary.amount') + 0.0);
        $this->get("/api/hr/payroll/{$sheet}/export")->assertOk();
        $this->postJson('/api/me/mfa/lock');
        $this->postJson('/api/me/mfa/backup/verify', ['code' => $codes[0]])->assertOk();
        $this->assertSame(300, $this->getJson('/api/me/mfa')->json('data.remaining'));

        // ينتهي الفتح بعد 5 دقائق، والقفل بالزر فوري
        Carbon::setTestNow(now()->addMinutes(5)->addSecond());
        $this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', true);
        $this->assertSame(1, DB::table('mfa_audit')->where('ok', true)->where('method', 'enrol')->count());
    }

    public function test_totp_code_is_single_use_and_wrong_attempts_lock_out(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        [$secret] = $this->enrol();
        $this->postJson('/api/me/mfa/lock')->assertOk()->assertJsonPath('data.unlocked', false);
        // نفس رمز الإعداد لا يُقبل مرة ثانية
        $this->postJson('/api/me/mfa/totp/verify', ['code' => SalaryVault::totp($secret, SalaryVault::step())])->assertStatus(422)->assertJsonPath('code', 'reused');
        Carbon::setTestNow(now()->addSeconds(30));
        $this->postJson('/api/me/mfa/totp/verify', ['code' => SalaryVault::totp($secret, SalaryVault::step())])->assertOk()->assertJsonPath('data.unlocked', true);
        $this->postJson('/api/me/mfa/lock');

        $wrong = SalaryVault::totp($secret, SalaryVault::step() + 7);
        for ($i = 4; $i >= 1; $i--) $this->postJson('/api/me/mfa/totp/verify', ['code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'wrong')->assertJsonPath('left', $i);
        $this->postJson('/api/me/mfa/totp/verify', ['code' => $wrong])->assertStatus(429)->assertJsonPath('code', 'locked')->assertJsonPath('retry', 60);
        Carbon::setTestNow(now()->addSeconds(30));
        $this->postJson('/api/me/mfa/totp/verify', ['code' => SalaryVault::totp($secret, SalaryVault::step())])->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(31));
        $this->postJson('/api/me/mfa/totp/verify', ['code' => SalaryVault::totp($secret, SalaryVault::step())])->assertOk();
        $this->assertSame(5, DB::table('mfa_audit')->where('ok', false)->where('method', 'app')->count());
    }

    public function test_backup_codes_work_once_and_password_reset_unlinks(): void
    {
        $u = $this->login('samer.halabi@alfarada.sy');
        [, $codes] = $this->enrol();
        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{4}-[A-Z2-7]{4}$/', $codes[0]);
        $this->assertNotContains($codes[0], MfaFactor::where('user_id', $u->id)->first()->backup_hashes);   // مجزّأة
        $this->postJson('/api/me/mfa/lock');
        $this->postJson('/api/me/mfa/backup/verify', ['code' => strtolower($codes[0])])->assertOk()->assertJsonPath('data.backup_left', 7);
        $this->postJson('/api/me/mfa/lock');
        $this->postJson('/api/me/mfa/backup/verify', ['code' => $codes[0]])->assertStatus(422);

        // «غيّرت جوالك؟»
        $this->deleteJson('/api/me/mfa', ['password' => 'nope'])->assertStatus(422);
        $this->deleteJson('/api/me/mfa', ['password' => '123456'])->assertOk()->assertJsonPath('data.enrolled', false);
        $this->assertDatabaseMissing('mfa_factors', ['user_id' => $u->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mfa.reset']);
    }

    public function test_one_time_code_by_email(): void
    {
        Notification::fake();
        $u = $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/me/services')->assertOk()->assertJsonPath('masked', true)->assertJsonPath('advance.available', null);
        $this->getJson('/api/me/payslips')->assertOk()->assertJsonPath('masked', true)->assertJsonPath('current.money', null);
        $this->postJson('/api/me/mfa/otp/send')->assertOk()->assertJsonPath('data.otp.expires_in', 120);
        $this->postJson('/api/me/mfa/otp/send')->assertStatus(429)->assertJsonPath('code', 'otp_wait');
        $code = null;
        Notification::assertSentTo($u, SalaryCode::class, function (SalaryCode $n) use (&$code) { $code = $n->code; return true; });
        $this->postJson('/api/me/mfa/otp/verify', ['code' => $code === '000000' ? '111111' : '000000'])->assertStatus(422)->assertJsonPath('left', 4);
        $this->postJson('/api/me/mfa/otp/verify', ['code' => $code])->assertOk()->assertJsonPath('data.unlocked', true);
        $this->assertNotNull($this->getJson('/api/me/payslips')->json('current.money.net'));
        $this->assertNotNull($this->getJson('/api/me/services')->json('advance.available'));
        // الرمز لمرة واحدة، وينتهي بعد دقيقتين
        $this->postJson('/api/me/mfa/otp/verify', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'expired');
        Carbon::setTestNow(now()->addMinute());
        $this->postJson('/api/me/mfa/otp/send')->assertOk();
        Carbon::setTestNow(now()->addSeconds(121));
        $this->postJson('/api/me/mfa/otp/verify', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'expired');
    }

    public function test_passkey_register_and_verify(): void
    {
        config(['app.frontend_url' => 'https://atmata-uhr.duckdns.org']);
        $this->login('samer.halabi@alfarada.sy');
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $pem = openssl_pkey_get_details($key)['key'];
        $spki = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
        $origin = 'https://alfarada.atmata-uhr.duckdns.org';
        $auth = fn (int $count, int $flags = 0x05) => hash('sha256', 'alfarada.atmata-uhr.duckdns.org', true).chr($flags).pack('N', $count);
        $client = fn (string $type, string $challenge, string $o = null) => json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $o ?? $origin]);
        $b = fn ($x) => SalaryVault::b64u($x);

        $ch = $this->postJson('/api/me/mfa/passkey/options')->assertOk()->json('data.challenge');
        $this->postJson('/api/me/mfa/passkey/register', ['id' => 'cred-1', 'client_data' => $b($client('webauthn.create', $ch, 'https://evil.example')), 'authenticator_data' => $b($auth(0)), 'public_key' => $b($spki), 'alg' => -7])
            ->assertStatus(422)->assertJsonPath('code', 'bad_signature');
        $ch = $this->postJson('/api/me/mfa/passkey/options')->json('data.challenge');
        $this->postJson('/api/me/mfa/passkey/register', ['id' => 'cred-1', 'client_data' => $b($client('webauthn.create', $ch)), 'authenticator_data' => $b($auth(1)), 'public_key' => $b($spki), 'alg' => -7, 'label' => 'Laptop'])
            ->assertOk()->assertJsonPath('data.unlocked', true)->assertJsonPath('data.passkeys.0.label', 'Laptop');
        $this->postJson('/api/me/mfa/lock');

        $sign = function (int $count, string $challenge) use ($client, $auth, $key, $b) {
            $json = $client('webauthn.get', $challenge); $a = $auth($count);
            openssl_sign($a.hash('sha256', $json, true), $sig, $key, OPENSSL_ALGO_SHA256);
            return ['id' => 'cred-1', 'client_data' => $b($json), 'authenticator_data' => $b($a), 'signature' => $b($sig)];
        };
        // توقيع بتحدٍّ قديم أو بعدّاد لم يتقدّم يُرفض
        $old = $ch;
        $this->postJson('/api/me/mfa/passkey/options');
        $this->postJson('/api/me/mfa/passkey/verify', $sign(2, $old))->assertStatus(422)->assertJsonPath('code', 'bad_signature');
        $ch = $this->postJson('/api/me/mfa/passkey/options')->json('data.challenge');
        $this->postJson('/api/me/mfa/passkey/verify', $sign(1, $ch))->assertStatus(422);
        $ch = $this->postJson('/api/me/mfa/passkey/options')->json('data.challenge');
        $this->postJson('/api/me/mfa/passkey/verify', $sign(2, $ch))->assertOk()->assertJsonPath('data.unlocked', true);
        $this->postJson('/api/me/mfa/lock');
        $id = $this->getJson('/api/me/mfa')->json('data.passkeys.0.id');
        $this->deleteJson("/api/me/mfa/passkey/{$id}")->assertOk()->assertJsonCount(0, 'data.passkeys');
    }

    public function test_company_settings_drive_the_vault(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/vault-settings')->assertOk()->json('data');
        $this->putJson('/api/hr/vault-settings', ['methods' => ['link' => false, 'phone' => false, 'passkey' => false, 'app' => false]] + $d)->assertStatus(422);
        $this->putJson('/api/hr/vault-settings', ['unlock_minutes' => 1, 'methods' => ['link' => false, 'phone' => false, 'passkey' => true, 'app' => true]] + $d)->assertOk()->assertJsonPath('data.unlock_minutes', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.salary_vault']);
        $this->postJson('/api/me/mfa/otp/send')->assertStatus(403)->assertJsonPath('code', 'method_off');
        $this->enrol();
        $this->assertSame(60, $this->getJson('/api/me/mfa')->json('data.remaining'));

        $this->login('samer.halabi@alfarada.sy');
        $this->putJson('/api/hr/vault-settings', $d)->assertForbidden();
        Tenant::forget();
    }

    public function test_phone_approval_unlocks_the_computer(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/mfa/link')->assertOk()->json('data');
        $this->assertSame(120, $l['expires_in']);
        $this->assertStringEndsWith('/approve/'.$l['token'], $l['url']);
        $this->getJson("/api/me/mfa/link/{$l['token']}")->assertOk()->assertJsonPath('data.status', 'pending');
        // الموبايل (جلسة أخرى بنفس الحساب) يرى الطلب ويوافق
        $this->getJson("/api/me/mfa/link/{$l['token']}/info")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/me/mfa/link/{$l['token']}", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/me/mfa/link/{$l['token']}", ['approve' => true])->assertStatus(409);
        $this->getJson("/api/me/mfa/link/{$l['token']}")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.state.unlocked', true);
        $this->getJson("/api/me/mfa/link/{$l['token']}")->assertJsonPath('data.status', 'expired');   // لمرة واحدة
        $this->assertSame('link', $this->getJson('/api/me/mfa')->json('data.last_ok.method'));
        // حساب آخر لا يستطيع الموافقة، والطلب ينتهي بعد link_seconds
        $l2 = $this->postJson('/api/me/mfa/link')->json('data');
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/me/mfa/link/{$l2['token']}", ['approve' => true])->assertForbidden()->assertJsonPath('code', 'link_other_user');
        Carbon::setTestNow(now()->addSeconds(121));
        $this->getJson("/api/me/mfa/link/{$l2['token']}/info")->assertStatus(410);
    }

    public function test_unlock_window_slides_with_use_up_to_the_maximum(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $this->enrol();
        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(now()->addMinutes(4));
            $r = $this->getJson('/api/me/payslips')->assertOk()->assertJsonPath('masked', false);
            $this->assertSame('300', $r->headers->get('X-Salary-Remaining'));
        }
        // بدون اطلاع 5 دقائق ← تُقفل
        Carbon::setTestNow(now()->addMinutes(5)->addSecond());
        $this->getJson('/api/me/payslips')->assertJsonPath('masked', true);
        // الحد الأقصى: 60 دقيقة من لحظة التحقق مهما استُخدمت
        $this->postJson('/api/me/mfa/otp/send');
        $this->postJson('/api/me/mfa/lock');
        Carbon::setTestNow(now()->addSeconds(31));
        $code = null;
        \Illuminate\Support\Facades\Notification::fake();
        $this->postJson('/api/me/mfa/otp/send')->assertOk();
        \Illuminate\Support\Facades\Notification::assertSentTo(User::where('email', 'samer.halabi@alfarada.sy')->first(), SalaryCode::class, function ($n) use (&$code) { $code = $n->code; return true; });
        $this->postJson('/api/me/mfa/otp/verify', ['code' => $code])->assertOk();
        for ($i = 0; $i < 14; $i++) { Carbon::setTestNow(now()->addMinutes(4)); $this->getJson('/api/me/payslips')->assertJsonPath('masked', false); }
        $this->assertSame('240', $this->getJson('/api/me/payslips')->headers->get('X-Salary-Remaining'));
        Carbon::setTestNow(now()->addMinutes(4)->addSecond());
        $this->getJson('/api/me/payslips')->assertJsonPath('masked', true);
    }

    public function test_others_salaries_need_a_renewable_grant_from_the_general_manager(): void
    {
        $month = Payroll::currentMonth();
        $hr = $this->login('hr@alfarada.sy');
        $this->postJson('/api/hr/payroll', ['month' => $month])->assertCreated();
        $this->unlockSalaries(false);
        $this->getJson('/api/me/mfa')->assertJsonPath('data.grant.needed', true)->assertJsonPath('data.grant.last', null);
        $p = $this->getJson("/api/hr/payroll?month={$month}")->assertOk()->assertJsonPath('masked', true)->assertJsonPath('sheet.totals.net', null);
        $mine = collect($p->json('rows'))->firstWhere('employee.id', $hr->employee_id);
        $this->assertNotNull($mine['money']);                                            // كشفي أنا يكفيه التحقق
        $this->assertNull(collect($p->json('rows'))->firstWhere('employee.code', 'UHR-1007')['money']);
        $this->get('/api/hr/payroll/'.$p->json('sheet.id').'/export')->assertStatus(403)->assertJsonPath('code', 'salary_grant_required');

        $this->postJson('/api/me/salary-access', ['scope' => 'all', 'reason' => 'قصير'])->assertStatus(422);
        $this->postJson('/api/me/salary-access', ['scope' => 'all', 'reason' => 'مراجعة كشف تشرين الأول قبل الإغلاق'])->assertOk()->assertJsonPath('data.0.status', 'pending_exec');
        $this->postJson('/api/me/salary-access', ['scope' => 'all', 'reason' => 'مراجعة كشف تشرين الأول قبل الإغلاق'])->assertStatus(409)->assertJsonPath('code', 'grant_open');

        // المدير العام يعتمد من صندوقه الموحد
        $this->login('hani.samman@alfarada.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'salary');
        $this->assertSame('all', $row['scope']);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->unlockSalaries(false);
        $this->assertNotNull($this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', false)->json('sheet.totals.net'));   // المعتمِد معفى

        $this->login('hr@alfarada.sy');
        $this->unlockSalaries(false);
        $this->getJson('/api/me/mfa')->assertJsonPath('data.grant.last.status', 'approved');
        $this->assertNotNull($this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', false)->json('sheet.totals.net'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'salary.access_granted']);

        // ينتهي بعد 8 ساعات ← طلب جديد بنطاق إدارة واحدة
        Carbon::setTestNow(now()->addHours(8)->addMinute());
        $this->unlockSalaries(false);
        $this->getJson('/api/me/mfa')->assertJsonPath('data.grant.last.status', 'expired');
        $this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', true);
        $dept = \App\Models\Employee::allCompanies()->where('code', 'UHR-1007')->value('department_id');
        $this->postJson('/api/me/salary-access', ['scope' => 'department', 'department_id' => $dept, 'reason' => 'تدقيق رواتب قسم تقنية المعلومات'])->assertOk();
        $this->login('hani.samman@alfarada.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'salary');
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->login('hr@alfarada.sy');
        $this->unlockSalaries(false);
        $rows = collect($this->getJson("/api/hr/payroll?month={$month}")->assertJsonPath('masked', true)->json('rows'));
        $this->assertNotNull($rows->firstWhere('employee.code', 'UHR-1007')['money']);
        $this->assertNull($rows->first(fn ($r) => $r['employee']['dept']['id'] !== $dept && $r['employee']['id'] !== $hr->employee_id)['money']);

        // السحب: المعتمِد فقط
        $id = $row['id'];
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson("/api/hr/vault-settings/grants/{$id}/revoke")->assertForbidden();
        $this->login('hani.samman@alfarada.sy');
        $this->postJson("/api/hr/vault-settings/grants/{$id}/revoke", ['note' => 'انتهى التدقيق'])->assertOk();
        $this->login('hr@alfarada.sy');
        $this->unlockSalaries(false);
        $this->assertNull(collect($this->getJson("/api/hr/payroll?month={$month}")->json('rows'))->firstWhere('employee.code', 'UHR-1007')['money']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'salary.access_revoked']);
    }
}
