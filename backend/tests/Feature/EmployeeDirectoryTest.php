<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Notice;
use App\Models\User;
use App\Services\People\Directory;
use App\Services\People\EmployeeDocs;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/** M07 — دليل الموظفين، الملف الوظيفي، التعديل وبطاقة NFC، الملفات الإضافية، التصدير، والهيكل التنظيمي */
class EmployeeDirectoryTest extends TestCase
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
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    private function emp(string $code): Employee
    {
        return Employee::allCompanies()->where('code', $code)->firstOrFail();
    }

    public function test_directory_filters_and_company_isolation(): void
    {
        $this->login('hr@alfarada.sy');
        $r = $this->getJson('/api/hr/employees')->assertOk();
        $this->assertCount(32, $r->json('data'));
        $this->assertSame(32, $r->json('current'));
        $this->assertSame(8, $r->json('departments'));
        $this->assertSame('UHR-1001', $r->json('data.0.code'));                         // مرتب حسب الرقم
        $this->assertSame(['create' => true, 'export' => true], $r->json('can'));

        $this->assertSame(['UHR-1012'], collect($this->getJson('/api/hr/employees?q=1012')->json('data'))->pluck('code')->all());
        $this->assertNotEmpty($this->getJson('/api/hr/employees?q=samer')->json('data'));   // بالاسم الإنكليزي
        $it = Tenant::run($this->emp('UHR-1012')->company_id, fn () => Department::where('code', 'it')->value('id'));
        $this->assertTrue(collect($this->getJson("/api/hr/employees?dept={$it}")->json('data'))->every(fn ($e) => $e['dept']['code'] === 'it'));
        $this->assertSame(['UHR-1028'], collect($this->getJson('/api/hr/employees?status=resigning')->json('data'))->pluck('code')->all());
        $this->getJson('/api/hr/employees?status=nope')->assertUnprocessable();

        // HR شركة أخرى لا يرى موظفي الفرادة
        $this->login('hr@atmata.sy');
        $this->assertNotContains('UHR-1012', collect($this->getJson('/api/hr/employees')->json('data'))->pluck('code')->all());
        $this->getJson('/api/hr/employees/'.$this->emp('UHR-1012')->id)->assertNotFound();
        // حساب الموظف لا يدخل نظام الشركة
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/employees')->assertForbidden();
    }

    public function test_scope_team_and_own(): void
    {
        $alaa = User::where('email', 'alaa.sheikhani@alfarada.sy')->firstOrFail();   // رئيس تقنية المعلومات: مدير بنطاق فريقه
        Tenant::set($alaa->company);
        $team = Directory::scoped($alaa)->pluck('code')->sort()->values()->all();
        $this->assertContains('UHR-1008', $team);
        $this->assertCount(1 + Employee::where('manager_id', $alaa->employee_id)->count(), $team);
        $this->assertFalse(Directory::visible($alaa, $this->emp('UHR-1003')->id));
        $samer = User::where('email', 'samer.halabi@alfarada.sy')->firstOrFail();
        $this->assertSame([$samer->employee_id], Directory::scoped($samer)->pluck('id')->all());
    }

    public function test_file_has_tabs_entitlement_and_attendance(): void
    {
        $this->login('hr@alfarada.sy');
        $e = $this->emp('UHR-1012');
        $d = $this->getJson("/api/hr/employees/{$e->id}")->assertOk()->json('data');
        $this->assertSame('UHR-1004', $this->emp('UHR-1004')->code);
        $this->assertSame($this->emp('UHR-1004')->id, $d['manager']['id']);
        $this->assertSame('08:00 – 16:00', $d['shift']);
        $this->assertSame(Directory::entitlement($e), $d['leave']['entitlement']);
        $this->assertEquals($d['leave']['entitlement'] - $e->leave_used, $d['leave']['remaining']);
        $this->assertCount(14, $d['attendance']);
        $this->assertSame('2026-10-12', $d['attendance'][0]['date']);
        $this->assertTrue(collect($d['attendance'])->every(fn ($r) => in_array($r['status'], ['before_start', 'leave', 'holiday', 'not_in', 'weekend', 'absent', 'justified', 'late', 'present'], true)));
        $this->assertArrayNotHasKey('salary', $d);                                  // الراتب محمي (المهمة 10)
        $this->assertNotEmpty($d['assets']);
        $this->assertArrayHasKey('services', $d);
        $this->assertTrue($d['can']['edit']);
    }

    public function test_entitlement_follows_the_policy(): void
    {
        Tenant::set($this->emp('UHR-1012')->company);
        $e = new Employee(['hire_date' => '2026-03-01', 'dob' => '1995-01-01']);
        $this->assertSame(14, Directory::entitlement($e, '2026-10-12'));            // أقل من سنة
        $e->hire_date = '2024-01-01';
        $this->assertSame(21, Directory::entitlement($e, '2026-10-12'));
        $e->dob = '1970-01-01';
        $this->assertSame(30, Directory::entitlement($e, '2026-10-12'));            // عمر 50+
        \App\Models\Policy::create(['key' => 'annual_leave', 'effective_from' => '2026-01-01', 'value' => ['first_year' => 10, 'standard' => 18, 'senior' => 28, 'senior_years' => 10, 'senior_age' => 60]]);
        Settings::flush();
        $this->assertSame(18, Directory::entitlement($e, '2026-10-12'));            // السياسة الجديدة نافذة
    }

    public function test_edit_profile_logs_moves_and_refuses_loops(): void
    {
        $this->login('hr@alfarada.sy');
        $e = $this->emp('UHR-1012');
        $base = $this->getJson("/api/hr/employees/{$e->id}")->json('data');
        $input = ['name' => $base['name_ar'], 'name_en' => $base['name_en'], 'gender' => $base['gender'], 'title_ar' => 'محاسب أول', 'title_en' => 'Senior accountant',
            'department_id' => $base['department_id'], 'location_id' => $base['location_id'], 'manager_id' => $this->emp('UHR-1001')->id,
            'email' => $base['email'], 'phone' => '+963 933 000 111', 'national_id' => $base['national_id'], 'dob' => $base['dob']];
        $d = $this->putJson("/api/hr/employees/{$e->id}", $input)->assertOk()->json('data');
        $this->assertSame('محاسب أول', $d['title']['ar']);
        $this->assertSame($this->emp('UHR-1001')->id, $d['manager']['id']);
        $this->assertStringContainsString('تغيير المسمى الوظيفي', collect($d['logs'])->pluck('ar')->implode('|'));
        $this->assertStringContainsString('تغيير المدير المباشر', collect($d['logs'])->pluck('ar')->implode('|'));
        $this->assertTrue(AuditLog::where('action', 'employee.updated')->where('subject_id', $e->id)->exists());

        // المدير لا يتبع لمرؤوسه، والبريد لا يتكرر
        $mgr = $this->emp('UHR-1004');
        $m = $this->getJson("/api/hr/employees/{$mgr->id}")->json('data');
        $loop = ['name' => $m['name_ar'], 'name_en' => $m['name_en'], 'gender' => $m['gender'], 'title_ar' => $m['title_ar'], 'title_en' => $m['title_en'], 'department_id' => $m['department_id'],
            'location_id' => $m['location_id'], 'manager_id' => Employee::allCompanies()->where('manager_id', $mgr->id)->value('id'), 'email' => $m['email']];
        $this->putJson("/api/hr/employees/{$mgr->id}", $loop)->assertUnprocessable()->assertJsonPath('code', 'manager_loop');
        $this->putJson("/api/hr/employees/{$e->id}", ['phone' => 'مطور أول'] + $input)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->putJson("/api/hr/employees/{$e->id}", ['email' => 'hr@alfarada.sy'] + $input)->assertUnprocessable()->assertJsonValidationErrors('email');

        // البريد الجديد يصبح اسم الدخول
        $this->putJson("/api/hr/employees/{$e->id}", ['email' => 'samer.new@alfarada.sy'] + $input)->assertOk();
        $this->assertSame('samer.new@alfarada.sy', User::where('employee_id', $e->id)->value('email'));
    }

    public function test_nfc_card_link_change_and_uniqueness(): void
    {
        $this->login('hr@alfarada.sy');
        $e = $this->emp('UHR-1012'); $other = $this->emp('UHR-1013');
        $this->putJson("/api/hr/employees/{$e->id}/card", ['uid' => '04a1b2c3d4'])->assertOk()->assertJsonPath('data.nfc_uid', '04:A1:B2:C3:D4');
        $this->putJson("/api/hr/employees/{$other->id}/card", ['uid' => '04:A1:B2:C3:D4'])->assertUnprocessable()->assertJsonPath('code', 'card_taken');
        $this->putJson("/api/hr/employees/{$e->id}/card", ['uid' => 'xyz'])->assertUnprocessable()->assertJsonPath('code', 'card_invalid');
        $logs = $this->putJson("/api/hr/employees/{$e->id}/card", ['uid' => null])->assertOk()->assertJsonPath('data.nfc_uid', null)->json('data.logs');
        $this->assertStringContainsString('إلغاء ربط بطاقة الحضور', $logs[0]['ar']);
    }

    public function test_documents_upload_sensitivity_log_archive_and_expiry(): void
    {
        $this->login('hr@alfarada.sy');
        $e = $this->emp('UHR-1007');                                     // سامر الحلبي
        $pdf = fn (string $n = 'id.pdf', int $kb = 40) => UploadedFile::fake()->create($n, $kb, 'application/pdf');

        // صيغة وحجم ونوع من الإعدادات
        $this->post("/api/hr/employees/{$e->id}/documents", ['file' => UploadedFile::fake()->create('x.exe', 10), 'type' => 'id'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'doc_ext');
        $this->post("/api/hr/employees/{$e->id}/documents", ['file' => $pdf('big.pdf', 11 * 1024), 'type' => 'id'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'doc_size');
        $this->post("/api/hr/employees/{$e->id}/documents", ['file' => $pdf(), 'type' => 'nope'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'doc_type');

        $id = $this->post("/api/hr/employees/{$e->id}/documents", ['file' => $pdf(), 'type' => 'id', 'expires_at' => '2026-11-01'], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertTrue($id['expiring']);                                         // خلال 30 يوماً
        $med = $this->post("/api/hr/employees/{$e->id}/documents", ['file' => $pdf('med.pdf'), 'type' => 'medical', 'sensitive' => 1], ['Accept' => 'application/json'])->assertCreated()->json('data');

        // المشاهدة والتنزيل يُسجَّلان في سجل المستند
        $this->get("/api/files/{$id['file']['id']}?inline=1")->assertOk();
        $this->get("/api/files/{$id['file']['id']}")->assertOk();
        $log = $this->getJson("/api/hr/employees/{$e->id}/documents/{$id['id']}/log")->assertOk()->json('data');
        $this->assertSame(['download', 'view', 'upload'], array_column($log, 'action'));

        // الحساس: المالية (عرض الموظفين) لا تراه ولا تنزّله
        $this->login('basel.atassi@alfarada.sy');
        $docs = $this->getJson("/api/hr/employees/{$e->id}")->assertOk()->json('data.documents');
        $this->assertSame([$id['id']], array_column($docs, 'id'));
        $this->get("/api/files/{$med['file']['id']}")->assertForbidden();
        $this->getJson("/api/hr/employees/{$e->id}/documents/{$med['id']}/log")->assertNotFound();
        $this->login('samer.halabi@alfarada.sy');                                  // صاحب المستند ينزّله
        $this->get("/api/files/{$med['file']['id']}")->assertOk();

        // الأرشفة بلا حذف، والاستعادة
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/employees/{$e->id}/documents/{$id['id']}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/hr/employees/{$e->id}/documents/{$id['id']}/archive", ['restore' => true])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(2, EmployeeDocument::allCompanies()->where('employee_id', $e->id)->count());
        $this->assertSame(['download', 'view', 'upload'], array_slice(array_column($this->getJson("/api/hr/employees/{$e->id}/documents/{$id['id']}/log")->json('data'), 'action'), 2));

        // المسح اليومي: تنبيه مرة واحدة قبل الانتهاء، ثم «منتهي»
        Tenant::set($e->company);
        $this->assertSame(['expired' => 0, 'alerted' => 1], app(EmployeeDocs::class)->sweep());
        $this->assertSame(['expired' => 0, 'alerted' => 0], app(EmployeeDocs::class)->sweep());
        $this->assertTrue(Notice::where('link', "emp:{$e->id}?tab=docs")->exists());
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'Asia/Damascus'));
        $this->assertSame(1, app(EmployeeDocs::class)->sweep()['expired']);
        $this->assertSame('expired', EmployeeDocument::find($id['id'])->status);
    }

    public function test_document_settings_are_validated_and_audited(): void
    {
        $this->login('hr@alfarada.sy');
        $s = $this->getJson('/api/hr/employee-docs/settings')->assertOk()->json();
        $this->assertSame(10, $s['data']['max_mb']);
        $new = ['types' => [['key' => 'id', 'ar' => 'هوية', 'en' => 'ID'], ['key' => 'visa', 'ar' => 'إقامة', 'en' => 'Residence permit']],
            'extensions' => ['pdf', 'png'], 'max_mb' => 5, 'alert_days' => 45, 'sensitive_roles' => ['hr_director']];
        $this->putJson('/api/hr/employee-docs/settings', $new)->assertOk()->assertJsonPath('data.alert_days', 45)->assertJsonPath('data.types.1.key', 'visa');
        $this->assertTrue(AuditLog::where('action', 'settings.docs')->exists());
        $this->putJson('/api/hr/employee-docs/settings', ['extensions' => ['php']] + $new)->assertUnprocessable()->assertJsonValidationErrors('extensions.0');
        $this->putJson('/api/hr/employee-docs/settings', ['max_mb' => 0] + $new)->assertUnprocessable()->assertJsonValidationErrors('max_mb');

        // نوع مستخدم لا يُحذف
        $e = $this->emp('UHR-1012');
        $this->post("/api/hr/employees/{$e->id}/documents", ['file' => UploadedFile::fake()->create('v.pdf', 20, 'application/pdf'), 'type' => 'visa'], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson('/api/hr/employee-docs/settings', ['types' => [['key' => 'id', 'ar' => 'هوية', 'en' => 'ID']]] + $new)->assertUnprocessable()->assertJsonValidationErrors('types');
        // hr_officer لم يعد يرى الحساس بعد الحفظ
        $officer = User::whereHas('employee', fn ($q) => $q->where('code', 'UHR-1005'))->firstOrFail();
        Tenant::set($officer->company);
        $this->assertFalse(EmployeeDocs::canSensitive($officer));

        // تعديل الإعدادات لـ settings,edit فقط
        $this->login('basel.atassi@alfarada.sy');
        $this->putJson('/api/hr/employee-docs/settings', $new)->assertForbidden();
    }

    public function test_export_needs_permission_and_is_audited(): void
    {
        $this->login('hr@alfarada.sy');
        $r = $this->get('/api/hr/employees/export?status=all')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $r->streamedContent() ?: file_get_contents($r->baseResponse->getFile()->getPathname()));
        $reader = new Reader(); $reader->open($path); $rows = 0;
        foreach ($reader->getSheetIterator() as $sheet) foreach ($sheet->getRowIterator() as $row) $rows++;
        $reader->close();
        $this->assertSame(33, $rows);                                               // ترويسة + 32
        $this->assertSame(32, AuditLog::where('action', 'employees.export')->latest('id')->first()->after['count']);

        $this->login('basel.atassi@alfarada.sy');                                  // المالية: عرض بلا تصدير
        $this->get('/api/hr/employees/export')->assertForbidden();
    }

    public function test_org_chart_lists_current_staff_under_their_managers(): void
    {
        $this->login('hr@alfarada.sy');
        $r = $this->getJson('/api/hr/org')->assertOk()->json();
        $this->assertCount(32, $r['data']);
        $this->assertCount(1, array_filter($r['data'], fn ($n) => $n['manager_id'] === null));     // جذر واحد
        $this->assertCount(8, $r['depts']);
        $this->assertTrue(collect($r['data'])->every(fn ($n) => $n['open']));

        // موظف جديد يظهر فوراً تحت رئيس إدارته
        $e = $this->emp('UHR-1012');
        Tenant::set($e->company);
        $new = Employee::create(['code' => 'UHR-2001', 'name' => 'تجربة', 'name_en' => 'Trial', 'gender' => 'M', 'title_ar' => 'محاسب', 'title_en' => 'Accountant',
            'department_id' => $e->department_id, 'location_id' => $e->location_id, 'email' => 'trial@alfarada.sy', 'hire_date' => '2026-10-12', 'status' => 'onboarding', 'manager_id' => $e->department->head_id]);
        $node = collect($this->getJson('/api/hr/org')->json('data'))->firstWhere('id', $new->id);
        $this->assertSame($e->department->head_id, $node['manager_id']);
    }
}
