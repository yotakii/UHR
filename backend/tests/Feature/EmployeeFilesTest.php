<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\Notice;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\People\EmployeeDocs;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** دفعة الملاحظات 15e: مستندات بتوقيع وموعد، ربط ملفات النظام، التوقيع والبصمة، ملفات بنود المباشرة، مذكرة الترحيب، نصوص النماذج، البحث، حسابي */
class EmployeeFilesTest extends TestCase
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

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function samer(): Employee { return Employee::allCompanies()->where('email', 'samer.halabi@uniqueness.sy')->firstOrFail(); }

    public function test_document_awaiting_signature_with_due_date_and_reminder(): void
    {
        $e = $this->samer();
        $this->login('hr@uniqueness.sy');
        $this->post("/api/hr/employees/{$e->id}/documents", ['file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'), 'type' => 'contract', 'title' => 'ملحق العقد',
            'needs_signature' => '1', 'sign_due' => '2026-10-20'])->assertCreated()->assertJsonPath('data.needs_signature', true)->assertJsonPath('data.sign_due', '2026-10-20');
        $this->post("/api/hr/employees/{$e->id}/documents", ['file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'), 'type' => 'other', 'needs_signature' => '1', 'sign_due' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('sign_due');
        $doc = EmployeeDocument::allCompanies()->where('title', 'ملحق العقد')->firstOrFail();
        $uid = User::where('employee_id', $e->id)->value('id');
        $this->assertTrue(Notice::where('user_id', $uid)->where('link', 'docs')->exists());

        Tenant::run($e->company_id, fn () => app(EmployeeDocs::class)->sweep());
        $this->assertNotNull($doc->fresh()->sign_alerted_at);

        $this->login('samer.halabi@uniqueness.sy');
        $this->getJson('/api/me/documents')->assertOk()->assertJsonPath('files.0.id', $doc->id);
        $this->postJson("/api/me/documents/files/{$doc->id}/sign", ['signature' => self::signature()])->assertOk()->assertJsonCount(0, 'files');
        $this->assertNotNull($doc->fresh()->signed_at);
        $this->postJson("/api/me/documents/files/{$doc->id}/sign", ['signature' => self::signature()])->assertStatus(409);

        $this->login('hr@uniqueness.sy');   // موظف آخر
        $this->postJson("/api/me/documents/files/{$doc->id}/sign", ['signature' => self::signature()])->assertNotFound();
    }

    public function test_link_system_file_and_biometrics(): void
    {
        $e = $this->samer();
        $f = Tenant::run($e->company_id, function () use ($e) {
            $f = StoredFile::create(['disk' => 'local', 'path' => 'x/report.pdf', 'name' => 'report.pdf', 'size' => 10, 'mime' => 'application/pdf']);
            LeaveRequest::create(['employee_id' => $e->id, 'type' => 'sick', 'from' => '2026-10-01', 'to' => '2026-10-02', 'days' => 2, 'reason' => 'مرض', 'status' => 'approved', 'doc_file_id' => $f->id]);
            return $f;
        });
        $other = Tenant::run($e->company_id, fn () => StoredFile::create(['disk' => 'local', 'path' => 'x/o.pdf', 'name' => 'o.pdf', 'size' => 10, 'mime' => 'application/pdf']));
        $this->login('hr@uniqueness.sy');
        $this->assertContains($f->id, collect($this->getJson("/api/hr/employees/{$e->id}/linkable")->assertOk()->json('data'))->pluck('id')->all());
        $this->postJson("/api/hr/employees/{$e->id}/documents/link", ['file_id' => $f->id, 'title' => 'تقرير طبي'])->assertCreated()->assertJsonPath('data.source', 'linked');
        $this->postJson("/api/hr/employees/{$e->id}/documents/link", ['file_id' => $f->id])->assertUnprocessable()->assertJsonPath('code', 'already_linked');
        $this->postJson("/api/hr/employees/{$e->id}/documents/link", ['file_id' => $other->id])->assertUnprocessable()->assertJsonPath('code', 'not_linkable');

        $this->post("/api/hr/employees/{$e->id}/biometrics", ['signature' => self::signature(), 'fingerprint' => UploadedFile::fake()->image('finger.png', 200, 200)])->assertOk();
        $file = $this->getJson("/api/hr/employees/{$e->id}")->assertOk()->json('data.biometrics');
        $this->assertStringStartsWith('data:image/png', $file['signature']);
        $this->get("/api/files/{$file['fingerprint_id']}")->assertOk();
        $this->login('samer.halabi@uniqueness.sy');
        $this->get("/api/files/{$file['fingerprint_id']}")->assertOk();   // صاحبها
    }

    public function test_onboarding_item_files_welcome_memo_and_form_text(): void
    {
        $this->login('hr@uniqueness.sy');
        $co = User::where('email', 'hr@uniqueness.sy')->value('company_id');
        $data = Tenant::run($co, fn () => ['name' => 'نور الخطيب', 'name_en' => 'Nour Al-Khatib', 'gender' => 'F', 'national_id' => '01090807060', 'dob' => '1997-03-02', 'phone' => '+963 944 123 456',
            'email' => 'nour.khatib@uniqueness.sy', 'department_id' => \App\Models\Department::where('code', 'it')->value('id'), 'title_ar' => 'محللة نظم', 'title_en' => 'Systems analyst',
            'location_id' => \App\Models\Location::where('code', 'dam')->value('id'), 'salary' => 950, 'currency' => 'USD', 'hire_date' => '2026-10-15']);
        $id = $this->postJson('/api/hr/employees', $data)->assertCreated()->json('data.id');
        $this->post("/api/hr/employees/{$id}/documents", ['file' => UploadedFile::fake()->create('medical.pdf', 20, 'application/pdf'), 'type' => 'onboarding', 'onboarding_item' => 7])->assertCreated();
        $this->getJson("/api/hr/onboarding/{$id}")->assertOk()->assertJsonCount(1, 'data.item_files.7');

        // 53: نص الشركة لمذكرة الترحيب والتعميم على الإدارة فقط
        $this->putJson('/api/hr/forms/welcome-memo', ['ar' => 'أهلاً :name في :unknown', 'en' => '', 'audience' => 'all'])->assertUnprocessable()->assertJsonPath('code', 'unknown_var');
        $this->putJson('/api/hr/forms/welcome-memo', ['ar' => 'نرحّب بـ :name في فريق :department بوظيفة :title.', 'en' => '', 'audience' => 'department'])->assertOk();
        $memo = $this->getJson("/api/hr/onboarding/{$id}")->json('data.memo.text');
        $this->assertSame('نرحّب بـ نور الخطيب في فريق تقنية المعلومات بوظيفة محللة نظم.', $memo['ar']);
        $this->assertStringContainsString('Nour', $memo['en']);   // الإنكليزي الافتراضي
        $before = Notice::count();
        $this->postJson("/api/hr/onboarding/{$id}/memo")->assertOk();
        $dept = Tenant::run($co, fn () => User::where('company_id', $co)->where('active', true)->whereIn('employee_id', Employee::where('department_id', $data['department_id'])->where('id', '!=', $id)->select('id'))->count());
        $this->assertSame($dept, Notice::count() - $before);

        // 54: نص قابل للتعديل في النموذج المطبوع
        $form = collect($this->getJson('/api/hr/forms')->json('data'))->firstWhere('code', 'F-32');
        $this->putJson("/api/hr/forms/{$form['id']}", ['ref' => $form['ref'], 'title_ar' => $form['title']['ar'], 'title_en' => $form['title']['en'], 'issue' => $form['issue'], 'issued_on' => $form['issued_on'] ?? '2026-01-01',
            'sensitive' => false, 'intro' => ['ar' => 'مقدمة النموذج', 'en' => ''], 'notes' => ['ar' => '', 'en' => '']])->assertOk()->assertJsonPath('data.intro.ar', 'مقدمة النموذج')->assertJsonPath('data.notes', null);
        $this->getJson('/api/print/F-32')->assertOk()->assertJsonPath('data.form.intro.ar', 'مقدمة النموذج');
    }

    public function test_search_reaches_real_data_and_account_settings(): void
    {
        $e = $this->samer();
        Tenant::run($e->company_id, fn () => $e->update(['phone' => '+963 944 555 777']));
        $this->login('hr@uniqueness.sy');
        $this->getJson('/api/hr/search?q=555777')->assertOk()->assertJsonPath('employees.0.id', $e->id);
        $asset = Tenant::run($e->company_id, fn () => \App\Models\Asset::first());
        $r = collect($this->getJson('/api/hr/search?q='.urlencode($asset->tag))->json('records'));
        $this->assertSame("/hr/custody?asset={$asset->id}", $r->firstWhere('kind', 'asset')['to']);
        $this->assertNotNull(collect($this->getJson('/api/hr/search?q='.urlencode('تقنية'))->json('records'))->firstWhere('kind', 'dept'));

        // 67: حسابي
        $this->getJson('/api/me/account')->assertOk()->assertJsonPath('data.employee.code', 'UHR-1003');
        $this->putJson('/api/me/account', ['locale' => 'en', 'theme' => 'light', 'name' => 'x'])->assertOk()->assertJsonPath('user.prefs.theme', 'light')->assertJsonPath('user.prefs.locale', 'en');
        $av = $this->post('/api/me/account/avatar', ['file' => UploadedFile::fake()->image('me.png', 120, 120)])->assertOk()->json('user.avatar');
        $this->post('/api/me/account/avatar', ['file' => UploadedFile::fake()->create('me.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->login('samer.halabi@uniqueness.sy');
        $this->get("/api/files/{$av}")->assertOk();   // صورة زميل في الشركة نفسها
        $this->login('hr@atmata.sy');
        $this->get("/api/files/{$av}")->assertForbidden();   // شركة أخرى
        $this->login('hr@uniqueness.sy');
        $this->deleteJson('/api/me/account/avatar')->assertOk()->assertJsonPath('user.avatar', null);
    }
}
