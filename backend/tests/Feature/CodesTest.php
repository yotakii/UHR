<?php

namespace Tests\Feature;

use App\Models\CodebookVersion;
use App\Models\CodeItem;
use App\Models\CodeList;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 18 — دليل الترميز (SRS الأنظمة الأساسية، الجزء A: AC-CS-01..03 + طلبات التغيير والدمج والإصدارات) */
class CodesTest extends TestCase
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

    private function uq(callable $fn): mixed { return Tenant::run(User::where('email', 'hr@uniqueness.sy')->value('company_id'), $fn); }

    public function test_change_requests_lock_reuse_merge_and_versions(): void
    {
        $this->login('hr@uniqueness.sy');
        $this->getJson('/api/cs')->assertOk()->assertJsonPath('golive.approved', true)->assertJsonPath('version.version', '1.0')->assertJsonPath('direct', false);
        // الدليل معتمد: لا تعديل مباشر
        $this->postJson('/api/cs/lists/grades/items', ['code' => 'G10', 'ar' => 'خبير', 'en' => 'Expert'])->assertStatus(409)->assertJsonPath('code', 'change_request_required');
        $this->postJson('/api/cs/requests', ['list' => 'grades', 'type' => 'add', 'payload' => ['code' => 'G1X', 'ar' => 'خبير', 'en' => 'Expert'], 'reason' => 'درجة جديدة للخبراء'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);   // النمط G + رقمان
        $rid = $this->postJson('/api/cs/requests', ['list' => 'grades', 'type' => 'add', 'payload' => ['code' => 'G10', 'ar' => 'خبير', 'en' => 'Expert', 'attrs' => ['rank' => 10]], 'reason' => 'درجة جديدة للخبراء'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson("/api/cs/requests/{$rid}/decide", ['approve' => true])->assertForbidden();   // لا يعتمد طلبه بنفسه

        $this->login('admin@uniqueness.sy');
        $this->postJson("/api/cs/requests/{$rid}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'applied')->assertJsonPath('version', '1.1');
        $this->assertContains('G10', array_column($this->getJson('/api/cs/lists/grades/items')->json('data'), 'code'));

        // AC-CS-03: لا يُعاد استخدام رمز ولو عُطّل — ويقترح التالي
        $this->login('hr@uniqueness.sy');
        $this->postJson('/api/cs/requests', ['list' => 'grades', 'type' => 'add', 'payload' => ['code' => 'G10', 'ar' => 'مكرر', 'en' => 'Dup'], 'reason' => 'محاولة تكرار'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->assertStringContainsString('G11', $this->postJson('/api/cs/requests', ['list' => 'grades', 'type' => 'add', 'payload' => ['code' => 'G10', 'ar' => 'مكرر', 'en' => 'Dup'], 'reason' => 'محاولة تكرار'])->json('errors.code.0'));

        // AC-CS-02: رمز مستخدم (مسمى مرتبط بموظفين) لا يتغير؛ الاسم يتغير
        $samer = Employee::allCompanies()->where('email', 'samer.halabi@uniqueness.sy')->firstOrFail();
        $job = CodeItem::withoutGlobalScopes()->findOrFail($samer->job_id);
        $this->postJson('/api/cs/requests', ['list' => 'jobs', 'type' => 'edit', 'item_id' => $job->id, 'payload' => ['code' => 'JB-IT-099'], 'reason' => 'تعديل الرمز'])
            ->assertUnprocessable()->assertJsonPath('code', 'code_locked');
        $r2 = $this->postJson('/api/cs/requests', ['list' => 'jobs', 'type' => 'edit', 'item_id' => $job->id, 'payload' => ['ar' => 'مهندس برمجيات أول'], 'reason' => 'توحيد التسمية'])->assertCreated()->json('id');
        // تعطيل بتاريخ سريان لاحق
        $r3 = $this->postJson('/api/cs/requests', ['list' => 'grades', 'type' => 'deactivate', 'item_id' => CodeItem::withoutGlobalScopes()->where('code', 'G10')->value('id'), 'reason' => 'لم تعد مستخدمة', 'effective_on' => '2026-10-20'])->assertCreated()->json('id');

        $this->login('admin@uniqueness.sy');
        $this->postJson("/api/cs/requests/{$r2}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'applied');
        $this->assertSame('مهندس برمجيات أول', $job->fresh()->name_ar);
        $this->postJson("/api/cs/requests/{$r3}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');   // ينتظر تاريخ السريان
        $this->assertContains('G10', array_column($this->getJson('/api/cs/lists/grades/items')->json('data'), 'code'));
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:00:00', 'Asia/Damascus'));
        $this->artisan('codes:apply')->assertSuccessful();
        $this->assertNotContains('G10', array_column($this->getJson('/api/cs/lists/grades/items')->json('data'), 'code'));
        $this->assertSame('inactive', CodeItem::withoutGlobalScopes()->where('code', 'G10')->value('status'));

        // إعادة هيكلة: دمج إدارة خدمة العملاء في العمليات — محاكاة ثم اعتماد من الإدارة العليا ونقل السجلات وجدول الربط
        [$cs, $ops, $n] = $this->uq(fn () => [Department::where('code', 'cs')->value('id'), Department::where('code', 'ops')->value('id'), Employee::where('department_id', Department::where('code', 'cs')->value('id'))->count()]);
        $this->login('hr@uniqueness.sy');
        $this->postJson('/api/cs/simulate', ['list' => 'departments', 'item_id' => $cs, 'target_id' => $ops])->assertOk()->assertJsonPath('tables.employees', $n);
        $m = $this->postJson('/api/cs/requests', ['list' => 'departments', 'type' => 'merge', 'item_id' => $cs, 'payload' => ['target_id' => $ops], 'reason' => 'دمج خدمة العملاء بالعمليات'])
            ->assertCreated()->assertJsonPath('impact.simulation.records', fn ($x) => $x >= $n)->json('id');
        $this->login('admin@uniqueness.sy');
        // المعتمد: دور إعادة الهيكلة (المدير العام) أو مدير النظام حسب الإعداد
        $this->uq(fn () => Settings::put('codes', [...Settings::merged('codes'), 'admin_approves' => false]));
        $this->postJson("/api/cs/requests/{$m}/decide", ['approve' => true])->assertForbidden();
        $this->login('hani.samman@uniqueness.sy');
        $this->postJson("/api/cs/requests/{$m}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'applied');
        $this->uq(function () use ($cs, $ops) {
            $this->assertSame(0, Employee::where('department_id', $cs)->count());
            $this->assertFalse(Department::find($cs)->active);
            $this->assertDatabaseHas('code_mappings', ['old_code' => 'cs', 'new_code' => 'ops', 'operation' => 'merge']);
        });

        // الإصدارات ومقارنتها
        $v = $this->getJson('/api/cs/versions')->assertOk()->json('data');
        $this->assertSame('1.0', end($v)['version']);
        $diff = $this->getJson('/api/cs/versions/compare?a='.end($v)['id'].'&b='.$v[0]['id'])->assertOk()->json('lists');
        $this->assertContains('grades', array_column($diff, 'key'));
        $this->assertContains('departments', array_column($diff, 'key'));
        $this->getJson('/api/cs/book')->assertOk()->assertJsonPath('version', $v[0]['version']);
        // قوائم النظام مقفلة
        $this->login('hr@uniqueness.sy');
        $this->postJson('/api/cs/requests', ['list' => 'currencies', 'type' => 'add', 'payload' => ['code' => 'XXX', 'ar' => 'س', 'en' => 'x'], 'reason' => 'محاولة'])->assertStatus(409);
    }

    public function test_golive_gate_blocks_new_employees_until_the_codebook_is_approved(): void
    {
        $this->uq(function () { CodebookVersion::query()->delete(); Settings::put('codes', [...Settings::merged('codes'), 'gate' => true]); });
        $this->login('hr@uniqueness.sy');
        $wizard = $this->uq(fn () => ['name' => 'نور الخطيب', 'name_en' => 'Nour Al-Khatib', 'gender' => 'F', 'national_id' => '01090807060', 'dob' => '1997-03-02', 'phone' => '+963 944 123 456',
            'email' => 'nour.khatib@uniqueness.sy', 'department_id' => Department::where('code', 'it')->value('id'), 'location_id' => Location::where('code', 'dam')->value('id'),
            'salary' => 950, 'currency' => 'USD', 'hire_date' => '2026-10-15', 'job' => CodeItem::where('code', 'like', 'JB-IT-%')->value('code'), 'grade' => 'G03', 'contract_type' => 'FT-D']);
        // AC-CS-01: ممنوع مع عرض نسبة الاكتمال
        $this->postJson('/api/hr/employees', $wizard)->assertStatus(409)->assertJsonPath('code', 'codebook_not_approved')->assertJsonPath('golive.percent', 100);
        // المباشر قبل الاعتماد مسموح؛ واعتماد الدليل للإدارة العليا فقط
        $this->postJson('/api/cs/lists/cost_centers/items', ['code' => 'CC-1020', 'ar' => 'الإدارة العامة', 'en' => 'Head office'])->assertCreated();
        $this->postJson('/api/cs/approve')->assertForbidden();
        $this->login('hani.samman@uniqueness.sy');
        $this->postJson('/api/cs/approve', ['note' => 'اعتماد الإدارة'])->assertOk()->assertJsonPath('version', '1.0');

        // قالب الرقم الوظيفي من الإعدادات: {CO}-{YY}-{SEQ4}
        $this->login('admin@uniqueness.sy');
        $this->getJson('/api/cs/generate/employee_no?template={CO}-{YY}-{SEQ4}')->assertOk()->assertJsonPath('code', 'UHR-26-0001');
        $s = $this->getJson('/api/cs')->json('settings');
        $this->putJson('/api/cs/settings', array_merge($s, ['company_code' => 'UNQ', 'templates' => ['employee_no' => ['template' => '{CO}-{XX}-{SEQ4}', 'start' => 1]]]))->assertUnprocessable();
        $this->putJson('/api/cs/settings', array_merge($s, ['company_code' => 'UNQ', 'templates' => ['employee_no' => ['template' => '{CO}-{YY}-{SEQ4}', 'start' => 147]]]))->assertOk();

        $this->login('hr@uniqueness.sy');
        $id = $this->postJson('/api/hr/employees', $wizard)->assertCreated()->assertJsonPath('data.code', 'UNQ-26-0147')->json('data.id');
        $e = $this->uq(fn () => Employee::with(['job', 'grade', 'contractType', 'workerCategory'])->findOrFail($id));
        $this->assertSame(['G03', 'FT-D', 'fixed', 'EMP'], [$e->grade->code, $e->contractType->code, $e->contract, $e->workerCategory->code]);
        $this->assertSame($e->job->name_ar, $e->title_ar);   // المسمى من الدليل يملأ العنوان
        $this->assertNotNull($e->grade->used_at);   // استُخدم ← مقفل
        $this->getJson("/api/hr/employees/{$id}")->assertOk()->assertJsonPath('data.codes.grade.code', 'G03');
        // رمز غير موجود في الدليل
        $this->postJson('/api/hr/employees', array_merge($wizard, ['email' => 'x.y@uniqueness.sy', 'national_id' => '01090807061', 'grade' => 'G99']))->assertUnprocessable()->assertJsonValidationErrors(['grade_id']);
    }

    public function test_reports_import_and_other_company_isolation(): void
    {
        $this->login('admin@uniqueness.sy');
        $this->assertSame([], $this->getJson('/api/cs/reports')->assertOk()->json('unused'));   // كلها أحدث من 12 شهراً
        Carbon::setTestNow(Carbon::parse('2027-12-01 10:00:00', 'Asia/Damascus'));
        $unused = $this->getJson('/api/cs/reports')->assertOk()->json('unused');
        $this->assertContains('G09', array_column($unused, 'code'));
        $this->assertNotContains(Employee::allCompanies()->where('email', 'samer.halabi@uniqueness.sy')->first()->job()->withoutGlobalScopes()->value('code'), array_column($unused, 'code'));
        $this->assertContains('cost_centers', array_column($this->getJson('/api/cs')->json('lists'), 'key'));
        $this->get('/api/cs/lists/grades/export')->assertOk();
        // شركة أخرى لا ترى رموز هذه الشركة
        $grade = $this->uq(fn () => CodeItem::where('code', 'G03')->first());
        $this->login('hr@atmata.sy');
        $ids = array_column($this->getJson('/api/cs/lists/grades/items')->assertOk()->json('data'), 'id');
        $this->assertNotContains($grade->id, $ids);
        $this->assertCount(9, $ids);
        $this->assertNotEmpty($this->getJson('/api/cs/lists/currencies/items')->json('data'));   // القوائم القياسية مشتركة
        $this->putJson("/api/cs/items/{$grade->id}", ['ar' => 'اختراق'])->assertNotFound();
    }
}
