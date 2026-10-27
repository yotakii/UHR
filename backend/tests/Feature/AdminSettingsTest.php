<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MfaFactor;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Support\Counters;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** M16 (11a): بيانات الشركة، الإدارات، سلاسل الاعتماد بنائب، المستخدمون والأدوار، قنوات الإشعار وقوالبها */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, string $password = '123456'): void
    {
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    public function test_company_identity_texts_and_prefixes(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/admin/company')->assertOk()->assertJsonPath('data.slug', 'alfarada')->json('data');
        unset($d['logo'], $d['slug']);
        $d['prefixes']['employee'] = 'unq1';
        $this->post('/api/hr/admin/company', $d, ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('prefixes.employee');
        $d['prefixes']['employee'] = 'FRD';
        $d['short_ar'] = 'الفرادة';
        $d['site']['hero_title_ar'] = 'انضم إلى فريق الفرادة';
        $d['website'] = 'www.uniqueness-sy.com';
        $this->post('/api/hr/admin/company', $d, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.prefixes.employee', 'FRD')->assertJsonPath('data.short_ar', 'الفرادة')->assertJsonPath('data.website', 'https://www.uniqueness-sy.com');
        $this->useCompany();
        $this->assertStringStartsWith('FRD-', Counters::employeeCode());
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.company']);
        $this->getJson('/api/public/companies/alfarada')->assertJsonPath('data.short.ar', 'الفرادة');

        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/admin/company')->assertForbidden();
    }

    public function test_departments_crud_and_guard(): void
    {
        $this->login('hr@alfarada.sy');
        $l = $this->getJson('/api/hr/admin/departments')->assertOk()->json();
        $this->assertCount(8, $l['data']);
        $this->postJson('/api/hr/admin/departments', ['code' => 'legal', 'ar' => 'الشؤون القانونية', 'en' => 'Legal', 'headcount' => 3, 'color' => 'info', 'active' => true])->assertOk();
        $this->postJson('/api/hr/admin/departments', ['code' => 'legal', 'ar' => 'x', 'en' => 'x', 'headcount' => 1, 'color' => 'info', 'active' => true])->assertStatus(422)->assertJsonValidationErrors('code');
        $it = collect($l['data'])->firstWhere('current', '>', 0);
        $this->putJson("/api/hr/admin/departments/{$it['id']}", ['code' => $it['code'], 'ar' => $it['ar'], 'en' => $it['en'], 'headcount' => $it['headcount'], 'color' => $it['color'], 'active' => false])
            ->assertStatus(409)->assertJsonPath('code', 'dept_in_use');
        $this->putJson("/api/hr/admin/departments/{$it['id']}", ['code' => $it['code'], 'ar' => 'إدارة معدّلة', 'en' => $it['en'], 'headcount' => 12, 'color' => 'brass', 'active' => true, 'head_id' => $it['head']['id'] ?? null])->assertOk();
        $this->assertDatabaseHas('departments', ['id' => $it['id'], 'name_ar' => 'إدارة معدّلة', 'headcount' => 12, 'color' => 'brass']);
    }

    public function test_approval_chain_with_deputy(): void
    {
        $basel = User::where('email', 'basel.atassi@alfarada.sy')->firstOrFail();
        $this->login('hr@alfarada.sy');
        $c = collect($this->getJson('/api/hr/admin/chains')->assertOk()->json('data'))->firstWhere('type', 'service');
        $this->assertSame('hr_director', $c['levels'][0]['role_key']);
        $this->putJson('/api/hr/admin/chains/appointment', ['levels' => [['role_key' => 'manager', 'role_ar' => 'م', 'role_en' => 'M']]])->assertStatus(422);
        $this->putJson('/api/hr/admin/chains/service', ['levels' => [['role_key' => 'hr_director', 'role_ar' => 'الموارد البشرية', 'role_en' => 'HR', 'deputy_id' => $basel->id]]])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.chain']);

        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/me/services', ['kind' => 'data_update', 'note' => 'تحديث عنوان السكن'])->assertCreated();
        // النائب (من المالية) يرى الطلب ويعتمده
        $this->login('basel.atassi@alfarada.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'service');
        $this->assertNotNull($row);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
    }

    public function test_users_roles_password_lock_and_mfa(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $this->getJson('/api/hr/admin/users')->assertForbidden();                // المستخدمون لمدير النظام
        $this->login('admin@alfarada.sy');
        $l = $this->getJson('/api/hr/admin/users')->assertOk()->json();
        $this->assertNotEmpty($l['roles']);
        $this->assertNotContains('hr@atmata.sy', array_column($l['data'], 'email'));      // حسابات شركة أخرى لا تظهر
        $atmata = User::where('email', 'hr@atmata.sy')->first();
        $this->postJson("/api/hr/admin/users/{$atmata->id}/deactivate")->assertNotFound();
        $r = $this->postJson('/api/hr/admin/users', ['email' => 'finance.audit@alfarada.sy', 'name' => 'مدقق مالي', 'roles' => ['finance']])->assertCreated();
        $temp = $r->json('temporary_password');
        $u = User::where('email', 'finance.audit@alfarada.sy')->firstOrFail();
        Notification::assertSentTo($u, AccountNotice::class);
        $this->postJson('/api/hr/admin/users', ['email' => 'finance.audit@alfarada.sy', 'name' => 'x', 'roles' => ['finance']])->assertStatus(422);

        // حساب لموظف بلا حساب: دور employee يُضاف تلقائياً
        $this->useCompany();
        $ref = Employee::where('code', 'UHR-1007')->first();
        $e = Employee::create(['code' => 'UHR-1950', 'name' => 'موظف جديد', 'name_en' => 'New Hire', 'gender' => 'M', 'email' => 'new.hire@alfarada.sy', 'title_ar' => 'محلل', 'title_en' => 'Analyst',
            'department_id' => $ref->department_id, 'location_id' => $ref->location_id, 'hire_date' => '2026-10-01', 'salary' => 900, 'currency' => 'USD', 'status' => 'active']);
        Tenant::forget();
        $this->postJson('/api/hr/admin/users', ['employee_id' => $e->id, 'roles' => ['manager']])->assertCreated()->assertJsonPath('data.roles', fn ($r) => in_array('employee', $r) && in_array('manager', $r));

        $this->putJson("/api/hr/admin/users/{$u->id}", ['roles' => ['finance', 'executive']])->assertOk()->assertJsonCount(2, 'data.roles');
        $me = User::where('email', 'admin@alfarada.sy')->first();
        $this->putJson("/api/hr/admin/users/{$me->id}", ['roles' => ['hr_director']])->assertStatus(409)->assertJsonPath('code', 'self_lockout');
        $this->postJson("/api/hr/admin/users/{$me->id}/deactivate")->assertStatus(409);

        $new = $this->postJson("/api/hr/admin/users/{$u->id}/reset-password")->assertOk()->json('temporary_password');
        $this->assertNotSame($temp, $new);
        MfaFactor::create(['user_id' => $u->id, 'totp_secret' => 'ABC', 'enrolled_at' => now()]);
        $this->postJson("/api/hr/admin/users/{$u->id}/reset-mfa")->assertOk()->assertJsonPath('data.mfa', false);
        $this->postJson("/api/hr/admin/users/{$u->id}/deactivate")->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated']);
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'finance.audit@alfarada.sy', 'password' => $new])->assertStatus(403);
    }

    public function test_channels_secrets_and_templates(): void
    {
        $this->login('hr@alfarada.sy');
        $this->putJson('/api/hr/admin/channels/email', ['enabled' => false])->assertStatus(422);
        $r = $this->putJson('/api/hr/admin/channels/sms', ['enabled' => true, 'config' => ['provider' => 'syriatel', 'sender' => 'ALFARADA', 'api_key' => 'sk-secret-1']])->assertOk()->json('channels');
        $sms = collect($r)->firstWhere('key', 'sms');
        $this->assertTrue($sms['enabled']);
        $this->assertSame(['set' => true, 'secret' => true], $sms['config']['api_key']);
        $this->assertStringNotContainsString('sk-secret-1', json_encode($r));
        // سر فارغ = يبقى المحفوظ
        $this->putJson('/api/hr/admin/channels/sms', ['enabled' => true, 'config' => ['provider' => 'mtn', 'sender' => 'ALFARADA', 'api_key' => '']])->assertOk();
        $this->useCompany();
        $this->assertSame('sk-secret-1', \App\Models\NotificationChannel::where('key', 'sms')->first()->config['api_key']);
        Tenant::forget();
        $t = collect($this->getJson('/api/hr/admin/channels')->json('templates'))->first(fn ($t) => $t['key'] === 'interview_invite' && $t['channel'] === 'email');
        $this->putJson("/api/hr/admin/templates/{$t['id']}", ['ar' => 'مرحباً :candidate', 'en' => 'Hi'])->assertStatus(422)->assertJsonValidationErrors('ar');
        $this->putJson("/api/hr/admin/templates/{$t['id']}", ['ar' => 'ندعوك لمقابلة :job يوم الأحد.', 'en' => 'Interview for :job on Sunday.'])->assertOk();
        $this->assertDatabaseHas('message_templates', ['id' => $t['id'], 'body_en' => 'Interview for :job on Sunday.']);
    }
}
