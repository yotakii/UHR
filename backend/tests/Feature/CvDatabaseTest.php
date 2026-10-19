<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Notice;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** قاعدة السير الذاتية (D-02): بحث مسجّل، تصدير بالصلاحية، أرشفة ومدة احتفاظ، حذف بطلب المتقدم، تنبيه المدير */
class CvDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Storage::fake('local');
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, string $pw = '123456'): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $pw])->assertOk();
    }

    public function test_search_filters_are_logged_and_export_needs_permission(): void
    {
        $this->login('hr@alfarada.sy');
        $all = $this->getJson('/api/hr/cv')->assertOk();
        $this->assertSame(10, $all->json('meta.total'));
        $this->assertNotEmpty($all->json('cities'));
        $this->assertLessThan(10, $this->getJson('/api/hr/cv?degree=3')->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/hr/cv?years=40')->json('meta.total'));
        $q = $this->getJson('/api/hr/cv?q=leen')->json('data');
        $this->assertCount(1, $q);
        $log = AuditLog::where('action', 'cvdb.search')->latest('id')->first();
        $this->assertSame(['q' => 'leen'], $log->after['filters']);
        $this->assertSame(1, $log->after['count']);

        $x = $this->get('/api/hr/cv/export?degree=2')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
        $this->assertSame(1, AuditLog::where('action', 'cvdb.export')->count());

        // المدير التنفيذي يعرض فقط ولا يصدّر، والموظف لا يصل
        $this->login('mazen.droubi@alfarada.sy');
        $this->getJson('/api/hr/cv')->assertOk();
        $this->get('/api/hr/cv/export', ['Accept' => 'application/json'])->assertForbidden();
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/cv')->assertForbidden();
    }

    public function test_archive_restore_and_retention(): void
    {
        $this->login('jobs@atmata.sy');                                  // مسؤول توظيف شركة أخرى: لا يرى سير الفرادة
        $this->assertSame(0, $this->getJson('/api/hr/cv')->json('meta.total'));

        $this->login('hr@alfarada.sy');
        $id = $this->getJson('/api/hr/cv')->json('data.0.id');
        $this->postJson("/api/hr/cv/{$id}/archive")->assertOk()->assertJsonPath('data.cv_status', 'archived');
        $this->assertSame(9, $this->getJson('/api/hr/cv')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/hr/cv?status=archived')->json('meta.total'));
        $this->postJson("/api/hr/cv/{$id}/archive", ['restore' => true])->assertOk()->assertJsonPath('data.cv_status', 'active');

        // مدة الاحتفاظ: بعد 24 شهراً بلا تحديث تُؤرشف تلقائياً
        Carbon::setTestNow(now()->addMonths(25));
        $this->artisan('cv:archive-expired')->assertSuccessful();
        $this->assertSame(0, Candidate::allCompanies()->where('cv_status', 'active')->where('stage', '!=', 'hired')->where('keep_cv', true)->count());
    }

    public function test_applicant_erases_data_with_password_and_an_anonymous_trace_stays(): void
    {
        $leen = Candidate::allCompanies()->where('email', 'leen.ali@mail.sy')->firstOrFail();
        $this->login('leen.ali@mail.sy');
        $this->assertTrue($this->getJson("/api/me/applications/{$leen->id}")->json('data.can_erase'));
        $this->postJson("/api/me/applications/{$leen->id}/erase", ['password' => 'wrong'])->assertStatus(422)->assertJsonPath('code', 'bad_password');
        $this->postJson("/api/me/applications/{$leen->id}/erase", ['password' => '123456'])->assertOk();

        $c = Candidate::allCompanies()->findOrFail($leen->id);
        $this->assertSame(['erased', 'محذوف بطلب المتقدم', null, null, [], 'rejected'], [$c->cv_status, $c->name, $c->national_id, $c->user_id, $c->education, $c->stage]);
        $this->assertSame($leen->ref, $c->ref);                              // أثر مجهّل: الرقم والشاغر والسجل
        $this->assertNull(StoredFile::find($leen->cv_file_id));
        $this->assertCount(0, $this->getJson('/api/me/applications')->json('data'));
        $this->assertSame(1, AuditLog::where('action', 'candidate.erased')->count());
        $this->assertFalse(AuditLog::where('subject_id', $c->id)->where('action', 'candidate.updated')->whereRaw('after::text like ?', ['%leen%'])->exists());

        // لا حذف لطلب قيد قرار التعيين
        $o = Candidate::allCompanies()->whereNotNull('user_id')->where('stage', '!=', 'rejected')->firstOrFail();
        \App\Support\Tenant::run($o->company_id, fn () => \App\Models\Appointment::create(['candidate_id' => $o->id, 'decision_no' => 'QR-T-1', 'issued_at' => now()]));
        try { \App\Support\Tenant::run($o->company_id, fn () => app(\App\Services\Cv\CvDatabase::class)->erase($o->refresh())); $this->fail('erase should be refused'); }
        catch (\App\Exceptions\ApiException $e) { $this->assertSame('erase_locked', $e->errorCode); }
    }

    public function test_department_manager_is_alerted_when_a_cv_arrives(): void
    {
        $form = [
            'name' => 'سارة محمود الأحمد', 'name_en' => 'Sara Al-Ahmad', 'gender' => 'F', 'marital' => 'single', 'nationality' => 'سورية', 'dob' => '1997-03-14', 'pob' => 'دمشق',
            'national_id' => '01020304050', 'mobile' => '+963 944 555 123', 'email' => 'sara.ahmad@mail.sy', 'address' => 'دمشق', 'degree' => 2, 'institute' => 'جامعة دمشق', 'major' => 'محاسبة',
            'grad_year' => 2019, 'english' => 'good', 'years_exp' => 4, 'experience' => [['company' => 'بنك', 'position' => 'محاسب', 'from' => 2020, 'to' => 2026]], 'shifts' => 0, 'travel' => 1,
            'public_sector' => 0, 'conviction' => 0, 'salary' => 750, 'source' => 'website', 'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            'password' => 'Sara2026pw', 'password_confirmation' => 'Sara2026pw', 'agree_info' => 1, 'agree_medical' => 1, 'agree_data' => 1,
        ];
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/public/jobs/alfarada/REQ-2026-034/apply', $form)->assertCreated();
        $basel = User::where('email', 'basel.atassi@alfarada.sy')->value('id');      // مدير الإدارة المالية
        $n = Notice::where('user_id', $basel)->latest('id')->first();
        $this->assertStringContainsString('سيرة ذاتية جديدة', $n->text_ar);
        $this->assertStringNotContainsString('سارة', $n->text_ar);
    }
}
