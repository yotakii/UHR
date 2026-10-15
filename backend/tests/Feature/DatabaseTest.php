<?php

namespace Tests\Feature;

use App\Models\ApprovalChain;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Policy;
use App\Models\Role;
use App\Models\User;
use App\Support\Counters;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tables_exist(): void
    {
        foreach (['users', 'roles', 'role_user', 'settings', 'policies', 'holidays', 'counters', 'files', 'event_logs', 'audit_logs',
            'locations', 'departments', 'employees', 'job_openings', 'devices', 'candidates', 'interviews', 'offers', 'appointments',
            'appointment_approvals', 'referrals', 'onboardings', 'attendance_days', 'scans', 'leave_requests', 'service_requests',
            'asset_categories', 'assets', 'resignations', 'rewards', 'redemptions', 'notices', 'notice_reads', 'announcements',
            'performance_cycles', 'competencies', 'performance_reviews', 'approval_chains', 'notification_channels',
            'message_templates', 'mfa_factors', 'passkeys', 'mfa_audit', 'jobs', 'failed_jobs', 'companies'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "missing table {$t}");
        }
    }

    public function test_default_seed_is_idempotent_and_production_safe(): void
    {
        $this->seed();
        $this->seed();
        $this->assertSame(11, Role::count());
        $this->assertSame(6, Location::count());
        $this->assertSame(0, ApprovalChain::count(), 'company defaults are seeded per company');
        $this->assertSame(0, Employee::count(), 'no sample people in a production seed');
        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::count(), 'seeding is not audited');
        $this->assertSame('UHR · ATMATA GROUP', Settings::platform('portal')['brand_latin']);

        // قيم الشركة تُبذر عند إنشائها، وتبقى معزولة عن الشركات الأخرى
        $a = $this->makeCompany('acme');
        \App\Services\Platform\CompanyDefaults::seed($a);
        $this->assertSame(3, ApprovalChain::count());
        $this->assertSame(4, count(ApprovalChain::where('type', 'appointment')->first()->levels));
        $this->assertSame('UHR', Settings::prefix('employee'));
        $this->assertNotNull(Settings::policy('annual_leave'));
        $this->assertSame('شركة acme', Settings::company('name_ar'));
        $this->makeCompany('beta');
        $this->assertSame(3, ApprovalChain::count());
        $this->assertSame(6, ApprovalChain::allCompanies()->count());
    }

    public function test_install_creates_admin_with_temporary_password(): void
    {
        $this->artisan('uhr:install', ['--email' => 'Owner@Atmata.sy', '--company' => 'alfarada', '--company-name-ar' => 'الفرادة', '--company-name-en' => 'Uniqueness', '--company-admin' => 'admin@alfarada.sy'])
            ->expectsOutputToContain('Temporary password')
            ->assertSuccessful();
        $u = User::where('email', 'owner@atmata.sy')->firstOrFail();
        $this->assertTrue($u->hasRole('platform_admin'));
        $this->assertTrue($u->must_change_password);
        $this->assertSame('platform', $u->portal());
        $admin = User::where('email', 'admin@alfarada.sy')->firstOrFail();
        $this->assertSame(['hr', 'alfarada', true], [$admin->portal(), $admin->company->slug, $admin->must_change_password]);
        $this->assertSame(1, AuditLog::where('action', 'system.installed')->count());
    }

    public function test_install_with_password_does_not_force_change_and_rejects_bad_email(): void
    {
        $this->artisan('uhr:install', ['--email' => 'a@b.sy', '--password' => 'Secret#2026'])->assertSuccessful();
        $u = User::where('email', 'a@b.sy')->first();
        $this->assertFalse($u->must_change_password);
        $this->assertTrue(Hash::check('Secret#2026', $u->password));
        $this->artisan('uhr:install', ['--email' => 'not-an-email'])->assertFailed();
    }

    public function test_counters_are_sequential_and_respect_floor(): void
    {
        $this->seed();
        $this->makeCompany('acme');
        $this->assertSame('UHR-1001', Counters::employeeCode());
        $this->assertSame('UHR-1002', Counters::employeeCode());
        Counters::atLeast('employee', 1032);
        $this->assertSame('UHR-1033', Counters::employeeCode());
        Counters::atLeast('employee', 5);   // لا يرجع للخلف
        $this->assertSame('UHR-1034', Counters::employeeCode());
        $this->assertSame('AST-0001', Counters::assetTag());
        $this->makeCompany('beta');   // كل شركة ترقيمها مستقل
        $this->assertSame('UHR-1001', Counters::employeeCode());
    }

    public function test_policies_resolve_by_effective_date(): void
    {
        $this->seed();
        $this->makeCompany('acme');
        Policy::create(['key' => 'probation_days', 'value' => 60, 'effective_from' => '2027-01-01']);
        $this->assertSame(Settings::policy('probation_days', '2026-06-01'), Settings::policy('probation_days', '2026-12-31'));
        $this->assertSame(60, Settings::policy('probation_days', '2027-01-02'));
    }

    public function test_writes_are_audited_with_changed_fields_only_and_secrets_masked(): void
    {
        $this->seed();
        $co = $this->makeCompany('acme');
        $d = Department::create(['code' => 'qa', 'name_ar' => 'الجودة', 'name_en' => 'Quality']);
        $d->update(['name_en' => 'Quality Assurance']);
        $d->delete();
        $logs = AuditLog::where('subject_type', $d->getMorphClass())->orderBy('id')->get();
        $this->assertSame(['department.created', 'department.updated', 'department.deleted'], $logs->pluck('action')->all());
        $this->assertSame(['name_en' => 'Quality'], $logs[1]->before);
        $this->assertSame(['name_en' => 'Quality Assurance'], $logs[1]->after);
        $this->assertSame($co->id, $logs[0]->company_id);

        $u = User::create(['email' => 'x@y.sy', 'password' => 'secret123']);
        $this->assertSame('••••', AuditLog::where('action', 'user.created')->latest('id')->first()->after['password']);
    }
}
