<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\FormTemplate;
use App\Models\Notice;
use App\Models\RolePermission;
use App\Models\StoredFile;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** الأساس (المهمة 05): مصفوفة الصلاحيات، لوحة المتابعة، الجرس، البحث، الملفات، الطباعة — SRS M15، §10.1–§10.5 */
class FoundationTest extends TestCase
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
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    public function test_default_matrix_follows_the_spec(): void
    {
        $this->assertSame(['actions' => ['view', 'create', 'print'], 'scope' => 'own'], Perm::default('employee', 'leaves'));
        $this->assertSame(['actions' => ['view', 'decide', 'print'], 'scope' => 'team'], Perm::default('manager', 'leaves'));
        $this->assertSame([], Perm::default('manager', 'payroll')['actions']);          // المدير لا يرى الرواتب أبداً
        $this->assertSame([], Perm::default('recruiter', 'employees')['actions']);      // مسؤول التوظيف لا يرى الموظفين
        $this->assertSame(['view', 'create', 'edit', 'archive'], Perm::default('admin', 'users')['actions']);
    }

    public function test_session_carries_permissions_and_routes_enforce_them(): void
    {
        $this->login('jobs@atmata.sy');
        $perms = $this->getJson('/api/auth/me')->json('user.perms');
        $this->assertSame(['jobs', 'candidates', 'referrals'], array_keys($perms));
        $this->getJson('/api/hr/jobs')->assertOk();
        $this->getJson('/api/hr/permissions')->assertForbidden();

        // HR يعرض الشواغر ويضيفها؛ المدير التنفيذي يعرضها فقط
        $this->login('hr@alfarada.sy');
        $this->getJson('/api/hr/jobs')->assertOk();
        $exec = \App\Models\User::whereHas('roles', fn ($q) => $q->where('key', 'executive'))->where('company_id', $this->company('alfarada'))->firstOrFail();
        $this->login($exec->email);
        $this->getJson('/api/hr/jobs')->assertOk();
        $this->postJson('/api/hr/jobs', [])->assertForbidden();
    }

    public function test_admin_edits_the_matrix_immediately_and_it_is_audited(): void
    {
        $this->login('admin@alfarada.sy');
        $grid = $this->getJson('/api/hr/permissions')->assertOk();
        $this->assertSame(['ع', 'ض', 'ت', 'ق', 'ص', 'ط', 'ح'], array_column($grid->json('actions'), 'letter'));
        $this->assertSame(32, collect($grid->json('roles'))->firstWhere('key', 'employee')['users']);

        // سحب «إضافة» الشواغر من مسؤول التوظيف + «عرض» يُضاف تلقائياً مع أي إجراء
        $this->putJson('/api/hr/permissions', ['matrix' => ['recruiter' => ['jobs' => ['actions' => ['view', 'edit'], 'scope' => 'all'], 'policies' => ['actions' => ['print'], 'scope' => 'all']]]])
            ->assertOk()->assertJsonPath('changed', 2)->assertJsonPath('matrix.recruiter.policies.actions', ['view', 'print']);
        $this->assertSame(2, RolePermission::allCompanies()->where('company_id', $this->company('alfarada'))->count());
        $log = AuditLog::where('action', 'permissions.updated')->latest('id')->firstOrFail();
        $this->assertSame('عضتح · all', $log->before['recruiter.jobs']);
        $this->assertSame('عت · all', $log->after['recruiter.jobs']);

        // لا يمس شركة أخرى، ولا يسحب المدير صلاحية اللوحة من دوره
        $this->assertSame(['view', 'create', 'edit', 'archive'], Perm::matrix($this->company('atmata'))['recruiter']['jobs']['actions']);
        $this->putJson('/api/hr/permissions', ['matrix' => ['admin' => ['users' => ['actions' => ['view'], 'scope' => 'all']]]])->assertStatus(422)->assertJsonPath('code', 'self_lockout');
        $this->putJson('/api/hr/permissions', ['matrix' => ['ghost' => ['jobs' => ['actions' => [], 'scope' => 'all']]]])->assertStatus(422)->assertJsonPath('code', 'unknown_role');
        $this->putJson('/api/hr/permissions', ['matrix' => ['recruiter' => ['jobs' => ['actions' => ['fly'], 'scope' => 'all']]]])->assertStatus(422);
    }

    public function test_dashboard_sections_follow_permissions(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/dashboard')->assertOk()->json('data');
        $this->assertSame(32, $d['people']['total']);
        $this->assertSame(7, count($d['attendance']['series']));
        $this->assertSame('2026-10-12', end($d['attendance']['series'])['date']);
        $this->assertSame(['new' => 3, 'screening' => 2, 'interview' => 2, 'technical' => 1, 'offer' => 1, 'hired' => 0], collect($d['funnel'])->pluck('count', 'stage')->all());
        $kinds = collect($d['tasks'])->pluck('kind')->unique()->sort()->values()->all();
        $this->assertContains('application', $kinds);
        $this->assertContains('leave', $kinds);
        $sorted = collect($d['tasks'])->pluck('at')->all();
        $this->assertSame($sorted, collect($sorted)->sortDesc()->values()->all());     // الأحدث أولاً

        // مسؤول التوظيف: التوظيف فقط، بلا بيانات موظفين أو حضور
        $this->login('jobs@atmata.sy');
        $r = $this->getJson('/api/hr/dashboard')->assertOk()->json('data');
        $this->assertArrayNotHasKey('people', $r);
        $this->assertArrayNotHasKey('attendance', $r);
        $this->assertArrayHasKey('funnel', $r);

        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/dashboard')->assertForbidden();
    }

    public function test_bell_shows_hr_notices_by_module_and_marks_them_read(): void
    {
        Tenant::run($this->company('alfarada'), function () {
            Notice::query()->delete();
            Notify::hr('طلب توظيف جديد', 'New application', 'cand:1');
            Notify::hr('طلب إجازة', 'Leave request', 'leaves');
        });
        $this->login('hr@alfarada.sy');
        $this->getJson('/api/notices')->assertOk()->assertJsonPath('unread', 2)->assertJsonCount(2, 'data');
        $this->postJson('/api/notices/read')->assertOk();
        $this->getJson('/api/notices')->assertJsonPath('unread', 0)->assertJsonPath('data.0.read', true);

        // نفس الإشعارات لمستخدم HR آخر: غير مقروءة عنده
        $officer = \App\Models\User::whereHas('roles', fn ($q) => $q->where('key', 'hr_officer'))->where('company_id', $this->company('alfarada'))->firstOrFail();
        $this->login($officer->email);
        $this->getJson('/api/notices')->assertJsonPath('unread', 2);

        // الموظف لا يرى إشعارات الموارد البشرية — إشعاراته فقط
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/notices')->assertJsonPath('unread', 0);
    }

    public function test_search_respects_permissions(): void
    {
        $this->login('hr@alfarada.sy');
        $r = $this->getJson('/api/hr/search?q=UHR-100')->assertOk();
        $this->assertCount(6, $r->json('employees'));
        $this->assertSame([], $this->getJson('/api/hr/search?q=a')->json('employees'));   // حرفان على الأقل
        $this->assertNotEmpty($this->getJson('/api/hr/search?q=leen')->json('candidates'));

        $this->login('jobs@atmata.sy');
        $this->assertSame([], $this->getJson('/api/hr/search?q=UHR-100')->json('employees'));
    }

    public function test_files_need_ownership_or_permission_and_downloads_are_logged(): void
    {
        Storage::fake('local');
        $cand = Candidate::allCompanies()->where('email', 'leen.ali@mail.sy')->firstOrFail();
        $file = Tenant::run($cand->company_id, function () use ($cand) {
            Storage::disk('local')->put('companies/x/cv/leen.pdf', '%PDF-1.4 test');
            $f = StoredFile::create(['disk' => 'local', 'path' => 'companies/x/cv/leen.pdf', 'name' => 'leen.pdf', 'size' => 13, 'mime' => 'application/pdf']);
            $cand->update(['cv_file_id' => $f->id]);
            return $f;
        });

        $this->getJson("/api/files/{$file->id}")->assertUnauthorized();
        $this->login('leen.ali@mail.sy');                       // صاحبة الملف
        $this->get("/api/files/{$file->id}")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=leen.pdf');
        $this->login('hr@alfarada.sy');                         // HR نفس الشركة
        $this->get("/api/files/{$file->id}?inline=1")->assertOk()->assertHeader('Content-Disposition', 'inline; filename=leen.pdf');
        $this->login('hr@atmata.sy');                           // شركة أخرى
        $this->getJson("/api/files/{$file->id}")->assertForbidden();
        $this->login('samer.halabi@alfarada.sy');               // موظف بلا صلاحية المتقدمين
        $this->getJson("/api/files/{$file->id}")->assertForbidden();

        $logs = AuditLog::where('action', 'file.download')->where('subject_id', $file->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('cv:'.$cand->ref, $logs[0]->after['of']);
    }

    public function test_forms_registry_print_meta_and_sensitive_print_log(): void
    {
        $this->login('hr@alfarada.sy');
        $forms = $this->getJson('/api/hr/forms')->assertOk();
        $this->assertSame('Uniqueness-HR.01-01-F-03', collect($forms->json('data'))->firstWhere('code', 'HR.01-01-F-03')['ref']);
        $this->assertSame('Uniqueness-HR-01-F-28', collect($forms->json('data'))->firstWhere('code', 'F-28')['ref']);

        $id = FormTemplate::allCompanies()->where('company_id', $this->company('alfarada'))->where('code', 'F-28')->value('id');
        $this->putJson("/api/hr/forms/{$id}", ['ref' => 'Uniqueness-HR-01-F-28', 'title_ar' => 'طلب توظيف', 'title_en' => 'Employment application', 'issue' => '02', 'issued_on' => '2026-01-15', 'sensitive' => false])->assertOk();
        $this->putJson('/api/hr/print-template', ['logo' => true, 'logo_height' => 64, 'align' => 'center', 'margin_mm' => 12])->assertOk();

        $meta = $this->getJson('/api/print/F-28')->assertOk()->json('data');
        $this->assertSame(['02', '2026-01-15'], [$meta['form']['issue'], $meta['form']['issued_on']]);
        $this->assertSame('center', $meta['template']['align']);
        $this->assertStringContainsString('/api/public/companies/alfarada/logo', $meta['company']['logo']);

        $this->postJson('/api/print/F-28', ['subject' => 'UNQ-1'])->assertJsonPath('logged', false);
        $this->postJson('/api/print/F-31', ['subject' => 'QR-2026-001'])->assertJsonPath('logged', true);
        $this->assertSame(1, AuditLog::where('action', 'print')->count());

        // المتقدم يطبع عرضه من الشركة التي قدّم لها فقط
        $this->login('leen.ali@mail.sy');
        $this->getJson('/api/print/F-29?company=alfarada')->assertOk()->assertJsonPath('data.form.ref', 'Uniqueness-HR-01-F-29');
        $this->getJson('/api/print/F-29?company=atmata')->assertNotFound();

        $this->login('jobs@atmata.sy');
        $this->getJson('/api/hr/forms')->assertForbidden();
    }

    private function company(string $slug): int
    {
        return \App\Models\Company::where('slug', $slug)->value('id');
    }
}
