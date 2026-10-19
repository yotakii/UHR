<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\PointLog;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\EmployeeWelcome;
use App\Services\Onboarding\F19;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** M06 المباشرة (F-19، F-32، العهد، التعهدات، التثبيت)، معالج إضافة موظف، الاستيراد، ونقاط الإحالة المرحلية */
class OnboardingTest extends TestCase
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

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function wizard(array $over = []): array
    {
        $co = User::where('email', 'hr@alfarada.sy')->value('company_id');
        return Tenant::run($co, fn () => array_merge([
            'name' => 'نور الخطيب', 'name_en' => 'Nour Al-Khatib', 'gender' => 'F', 'national_id' => '01090807060', 'dob' => '1997-03-02', 'phone' => '+963 944 123 456',
            'email' => 'nour.khatib@alfarada.sy', 'department_id' => \App\Models\Department::where('code', 'it')->value('id'), 'title_ar' => 'محللة نظم', 'title_en' => 'Systems analyst',
            'location_id' => \App\Models\Location::where('code', 'dam')->value('id'), 'salary' => 950, 'currency' => 'USD', 'hire_date' => '2026-10-15',
        ], $over));
    }

    public function test_wizard_creates_an_onboarding_employee_with_account_and_custody(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $lk = $this->getJson('/api/hr/employees/lookups')->assertOk()->json();
        $this->assertNotEmpty($lk['departments']); $this->assertNotEmpty($lk['assets']);
        $assets = array_slice(array_column($lk['assets'], 'id'), 0, 2);

        $this->postJson('/api/hr/employees', $this->wizard(['email' => 'hr@alfarada.sy', 'national_id' => 'x']))->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'national_id']);
        $id = $this->postJson('/api/hr/employees', $this->wizard() + ['asset_ids' => $assets])->assertCreated()->assertJsonPath('data.code', 'UHR-1033')->json('data.id');

        $e = Employee::findOrFail($id);
        $this->assertSame(['onboarding', 'UHR-1008'], [$e->status, $e->manager()->value('code')]);   // رئيس الإدارة تلقائياً
        $this->assertSame([0, 13], array_keys(array_filter($e->onboarding->checklist)));
        $this->assertSame(2, Asset::where('employee_id', $id)->where('status', 'pending_ack')->count());
        $u = User::where('email', 'nour.khatib@alfarada.sy')->firstOrFail();
        $this->assertSame([$id, true, 'employee'], [$u->employee_id, $u->must_change_password, $u->portal()]);
        Notification::assertSentTo($u, EmployeeWelcome::class);
        $this->assertSame(1, AuditLog::where('action', 'employee.added')->count());

        $page = $this->getJson("/api/hr/onboarding/{$id}")->assertOk();
        $this->assertSame([2, 28, 'Nour Al-Khatib'], [$page->json('data.done'), $page->json('data.total'), $page->json('data.name.en')]);
        $this->assertStringContainsString('بانضمام نور الخطيب إلى فريق', $page->json('data.memo.text.ar'));
        $this->assertStringContainsString('نتمنى لها بداية موفقة', $page->json('data.memo.text.ar'));
        $this->assertSame($id, collect($this->getJson('/api/hr/onboarding')->json('joiners'))->firstWhere('id', $id)['id']);

        // الموظف العادي لا يصل لصفحات المباشرة، ولا مسؤول التوظيف
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson("/api/hr/onboarding/{$id}")->assertForbidden();
        $this->login('jobs@atmata.sy');
        $this->getJson('/api/hr/onboarding')->assertForbidden();
        $this->postJson('/api/hr/employees', $this->wizard(['email' => 'x@atmata.sy']))->assertForbidden();
    }

    public function test_checklist_signatures_custody_probation_and_confirmation(): void
    {
        $this->login('hr@alfarada.sy');
        $assets = array_slice(array_column($this->getJson('/api/hr/employees/lookups')->json('assets'), 'id'), 0, 1);
        $id = $this->postJson('/api/hr/employees', $this->wizard() + ['asset_ids' => $assets])->json('data.id');
        $ob = fn () => Employee::findOrFail($id)->onboarding()->first();

        $this->postJson("/api/hr/onboarding/{$id}/check", ['index' => 10])->assertStatus(409)->assertJsonPath('code', 'auto_item');   // F-22 يُنجز بالتوقيع
        $this->postJson("/api/hr/onboarding/{$id}/check", ['index' => 1])->assertOk()->assertJsonPath('data.items.1.done', true);
        $this->postJson("/api/hr/onboarding/{$id}/memo")->assertOk()->assertJsonPath('data.items.4.done', true);
        $this->assertNotNull($ob()->memo_sent_at);

        // تسليم أصل متاح، ورفض أصل مسلّم، ثم إلغاء تسليم غير موقّع
        $free = Asset::where('status', 'available')->orderBy('id')->firstOrFail();
        $this->postJson("/api/hr/onboarding/{$id}/assets", ['asset_id' => $free->id])->assertOk();
        $this->postJson("/api/hr/onboarding/{$id}/assets", ['asset_id' => $free->id])->assertStatus(422)->assertJsonPath('code', 'asset_unavailable');
        $this->deleteJson("/api/hr/onboarding/{$id}/assets/{$free->id}")->assertOk();
        $this->assertSame('available', $free->refresh()->status);

        // الموظف يوقّع من حسابه: عهدة + F-22 + F-20 (باسم الأم) + الميثاق
        User::where('email', 'nour.khatib@alfarada.sy')->first()->update(['password' => '123456', 'must_change_password' => false]);
        $this->login('nour.khatib@alfarada.sy');
        $docs = $this->getJson('/api/me/documents')->assertOk();
        $this->assertCount(1, $docs->json('pending'));
        $this->postJson('/api/me/documents/sign', ['doc' => 'asset', 'asset_id' => $assets[0]])->assertStatus(422)->assertJsonPath('code', 'signature_required');
        $this->postJson('/api/me/documents/sign', ['doc' => 'asset', 'asset_id' => $assets[0], 'signature' => self::signature()])->assertOk()->assertJsonCount(1, 'signed');
        $this->postJson('/api/me/documents/sign', ['doc' => 'f22', 'signature' => self::signature()])->assertOk();
        $this->postJson('/api/me/documents/sign', ['doc' => 'f22', 'signature' => self::signature()])->assertStatus(409)->assertJsonPath('code', 'already_signed');
        $this->postJson('/api/me/documents/sign', ['doc' => 'f20', 'signature' => self::signature()])->assertStatus(422)->assertJsonPath('code', 'mother_required');
        $this->postJson('/api/me/documents/sign', ['doc' => 'f20', 'signature' => self::signature(), 'mother_name' => 'سلمى الحسن'])->assertOk();
        $r = $this->postJson('/api/me/documents/sign', ['doc' => 'conduct', 'signature' => self::signature()])->assertOk();
        $this->assertNotNull($r->json('undertakings.conduct.signed_at'));
        $this->assertStringStartsWith('data:image/png;base64,', $r->json('undertakings.f22.signature'));
        $this->assertSame([true, true, true], [$ob()->checklist[10], $ob()->checklist[12], $ob()->checklist[22]]);

        // 14 بنداً ← فترة التجربة تلقائياً
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/onboarding/{$id}/confirm")->assertStatus(409)->assertJsonPath('code', 'not_in_probation');
        foreach ([2, 3, 5, 6, 7, 8, 9] as $i) $this->postJson("/api/hr/onboarding/{$id}/check", ['index' => $i])->assertOk();
        $this->assertSame([14, 'probation'], [$ob()->doneCount(), Employee::find($id)->status]);
        $page = $this->getJson("/api/hr/onboarding/{$id}")->json('data');
        $this->assertSame('2027-01-10', $page['probation']['end']);   // 90 يوماً من بدء التجربة
        $this->postJson("/api/hr/onboarding/{$id}/confirm", ['note' => 'أداء ممتاز'])->assertOk()->assertJsonPath('data.items.27.done', true);
        $this->assertSame('active', Employee::find($id)->status);
        $this->postJson("/api/hr/onboarding/{$id}/check", ['index' => 9])->assertStatus(409)->assertJsonPath('code', 'onboarding_closed');
        $this->assertNotContains($id, collect($this->getJson('/api/hr/onboarding')->json('joiners'))->pluck('id'));
    }

    public function test_staged_referral_points_once_per_stage_doubled_for_hard_to_fill_and_none_after_rejection(): void
    {
        $this->login('hr@alfarada.sy');
        $c = Candidate::whereNotNull('referrer_id')->where('stage', 'new')->with('jobOpening')->first()
            ?? Candidate::whereNotNull('referrer_id')->with('jobOpening')->firstOrFail();
        $c->update(['stage' => 'new']);
        $c->jobOpening->update(['hard_to_fill' => false]);
        $ref = $c->referrer()->first(); $before = $ref->points;

        $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => 'screening'])->assertOk();
        $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => 'new'])->assertOk();
        $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => 'screening'])->assertOk();   // لا تكرار
        $this->assertSame($before + 10, $ref->refresh()->points);
        $c->jobOpening->update(['hard_to_fill' => true]);
        $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => 'interview'])->assertOk();   // المرحلة 2 × 2
        $this->assertSame($before + 10 + 40, $ref->refresh()->points);
        $this->assertSame([1, 2], PointLog::where('candidate_id', $c->id)->orderBy('stage')->pluck('stage')->all());
        $this->assertSame('processing', Referral::where('candidate_id', $c->id)->value('status'));

        $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => 'rejected', 'reason' => 'لم يجتز التقييم الفني المطلوب'])->assertOk();
        $this->assertSame('closed', Referral::where('candidate_id', $c->id)->value('status'));
        $this->assertNull(app(\App\Services\Referrals\ReferralPoints::class)->award($c->refresh(), 3));
        $this->assertSame($before + 50, $ref->refresh()->points);

        // المرحلة 3 عند التعيين، والمرحلة 4 عند تثبيت التعيين بعد التجربة
        $h = Candidate::whereNotNull('referrer_id')->where('id', '!=', $c->id)->with('jobOpening')->firstOrFail();
        $h->jobOpening->update(['hard_to_fill' => false]);
        $h->update(['stage' => 'hired']);
        Tenant::run($h->company_id, fn () => app(\App\Services\Referrals\ReferralPoints::class)->sync($h));
        $this->assertSame([1, 2, 3], PointLog::where('candidate_id', $h->id)->orderBy('stage')->pluck('stage')->all());
        $this->assertSame('hired', Referral::where('candidate_id', $h->id)->value('status'));
        $emp = Employee::allCompanies()->where('company_id', $h->company_id)->where('code', 'UHR-1030')->firstOrFail();
        $emp->update(['candidate_id' => $h->id, 'status' => 'probation']);
        \App\Models\Onboarding::create(['employee_id' => $emp->id, 'checklist' => F19::blank(...range(0, 13))]);
        $this->postJson("/api/hr/onboarding/{$emp->id}/confirm")->assertOk();
        $this->assertSame(40, PointLog::where('candidate_id', $h->id)->where('stage', 4)->value('points'));
        $this->assertSame(['rewarded', 100], [Referral::where('candidate_id', $h->id)->value('status'), (int) Referral::where('candidate_id', $h->id)->value('awarded')]);

        // القيم من القواعد
        $rules = $this->getJson('/api/hr/recruitment-rules')->json();
        $this->assertSame([10, 20, 30, 40], $rules['rules']['referral_stages']);
        $this->assertArrayNotHasKey('referral_points', $rules['rules']);
    }

    public function test_excel_import_previews_errors_and_saves_all_or_nothing(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $tpl = $this->get('/api/hr/employees/import/template')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $tpl->headers->get('Content-Type'));

        $file = function (array $rows): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
            $w = new Writer(); $w->openToFile($path);
            $w->addRow(Row::fromValues(array_map(fn ($k, $c) => "{$k} — {$c[0]}", array_keys(\App\Services\People\EmployeeImport::COLUMNS), \App\Services\People\EmployeeImport::COLUMNS)));
            foreach ($rows as $r) $w->addRow(Row::fromValues(array_map(fn ($k) => $r[$k] ?? '', array_keys(\App\Services\People\EmployeeImport::COLUMNS))));
            $w->close();
            return new UploadedFile($path, 'employees.xlsx', null, null, true);
        };
        $good = ['name' => 'مازن عيسى', 'name_en' => 'Mazen Issa', 'gender' => 'ذكر', 'email' => 'mazen.issa@alfarada.sy', 'department' => 'it', 'title_ar' => 'مهندس شبكات', 'title_en' => 'Network engineer',
            'location' => 'dam', 'salary' => '1,100', 'hire_date' => '2021-02-01', 'code' => 'UHR-2001'];
        $second = ['name' => 'رنا يوسف', 'name_en' => 'Rana Youssef', 'gender' => 'F', 'email' => 'rana.youssef@alfarada.sy', 'department' => 'IT', 'title_ar' => 'فنية دعم', 'title_en' => 'Support technician',
            'location' => 'Damascus', 'salary' => '700', 'hire_date' => '01/06/2023', 'manager_code' => 'UHR-2001'];
        $bad = ['name' => 'خطأ', 'name_en' => 'Bad', 'gender' => 'x', 'email' => 'mazen.issa@alfarada.sy', 'department' => 'nope', 'title_ar' => 'x', 'title_en' => 'x', 'location' => 'dam', 'salary' => 'abc', 'hire_date' => '2021-13-40'];

        $p = $this->post('/api/hr/employees/import/preview', ['file' => $file([$good, $second, $bad])])->assertOk();
        $this->assertSame([2, 1], [$p->json('ok'), $p->json('errors')]);
        $this->assertEqualsCanonicalizing(['gender', 'email', 'department_id', 'salary', 'hire_date'], array_keys($p->json('rows.2.errors')));
        $this->post('/api/hr/employees/import', ['file' => $file([$good, $second, $bad])])->assertStatus(422)->assertJsonPath('code', 'import_errors');
        $this->assertFalse(Employee::where('email', 'mazen.issa@alfarada.sy')->exists());

        $this->post('/api/hr/employees/import', ['file' => $file([$good, $second]), 'invite' => '0'])->assertCreated()->assertJsonPath('created', 2);
        $m = Employee::where('code', 'UHR-2001')->firstOrFail(); $r = Employee::where('email', 'rana.youssef@alfarada.sy')->firstOrFail();
        $this->assertSame(['active', 1100.0, false], [$m->status, (float) $m->salary, $m->onboarding()->exists()]);
        $this->assertSame([$m->id, '2023-06-01', 'UHR-2002'], [$r->manager_id, $r->hire_date->toDateString(), $r->code]);   // العداد بعد أعلى رقم مستورد
        $this->assertTrue(User::where('email', 'rana.youssef@alfarada.sy')->value('must_change_password'));
        Notification::assertNothingSent();
        $this->assertSame(1, AuditLog::where('action', 'employees.imported')->count());
    }
}
