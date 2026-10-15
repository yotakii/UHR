<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Notice;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ApplicationReceived;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** M02 — بوابة التوظيف F-28 */
class ApplicationTest extends TestCase
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

    /** طلب كامل صالح — كل الحقول الإلزامية معبّأة */
    private function form(array $over = []): array
    {
        return array_replace([
            'job' => 'REQ-2026-034', 'name' => 'سارة محمود الأحمد', 'name_en' => 'Sara Al-Ahmad', 'gender' => 'F', 'marital' => 'single', 'nationality' => 'سورية',
            'dob' => '1997-03-14', 'pob' => 'دمشق', 'national_id' => '01020304050', 'blood' => 'O+', 'mobile' => '+963 944 555 123', 'email' => 'Sara.Ahmad@Mail.sy',
            'address' => 'دمشق — المزة', 'linkedin' => 'linkedin.com/in/sara',
            'degree' => 2, 'institute' => 'جامعة دمشق', 'major' => 'محاسبة', 'grad_year' => 2019, 'grade' => 'جيد جداً', 'english' => 'good', 'years_exp' => 4,
            'experience' => [['company' => 'بنك سورية الدولي', 'position' => 'محاسب', 'from' => 2020, 'to' => 2026, 'reason' => 'تطور مهني'], ['company' => '', 'position' => '']],
            'ref_name' => 'م. سليم حداد', 'ref_job' => 'مدير سابق', 'ref_phone' => '+963 944 120 330',
            'shifts' => 0, 'travel' => 1, 'public_sector' => 0, 'conviction' => 0, 'salary' => 750, 'availability' => '2026-11-01', 'source' => 'website',
            'cv' => UploadedFile::fake()->create('Sara_CV.pdf', 240, 'application/pdf'),
            'password' => 'Sara2026pw', 'password_confirmation' => 'Sara2026pw', 'agree_info' => 1, 'agree_medical' => 1,
        ], $over);
    }

    private function submit(array $over = [], string $lang = 'ar')
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Accept-Language' => $lang])->post('/api/public/applications', $this->form($over));
    }

    public function test_complete_application_creates_file_account_and_notifies(): void
    {
        $res = $this->submit()->assertCreated()->assertJsonPath('data.email', 'sara.ahmad@mail.sy')->assertJsonPath('data.job.title.en', 'Accountant');
        $ref = $res->json('data.ref');
        $this->assertMatchesRegularExpression('/^UNQ-2026-APP-\d{5}$/', $ref);

        $c = Candidate::with(['cv', 'logs'])->where('ref', $ref)->firstOrFail();
        $this->assertSame('سارة محمود الأحمد', $c->name);
        $this->assertCount(1, $c->experience);                       // الخبرة الفارغة لا تُحفظ
        $this->assertSame('إجازة جامعية', $c->education[0]['phase']);
        $this->assertSame('Sara_CV.pdf', $c->cv->name);
        Storage::disk('local')->assertExists($c->cv->path);
        $this->assertGreaterThan(0, $c->match_score);
        $this->assertSame('F-28', $c->logs->first()->form);

        $user = User::with('roles')->where('email', 'sara.ahmad@mail.sy')->firstOrFail();
        $this->assertSame($c->id, $user->candidate_id);
        $this->assertSame('candidate', $user->portal());
        $this->assertTrue(Notice::where('audience', 'hr')->where('link', "cand:{$c->id}")->where('text_ar', 'like', 'طلب توظيف جديد من سارة%')->exists());
        Notification::assertSentTo($user, ApplicationReceived::class, fn ($n) => $n->ref === $ref);

        // «متابعة حالة الطلب»: الدخول بنفس البيانات يفتح بوابة المتقدم
        $this->postJson('/api/auth/login', ['email' => 'sara.ahmad@mail.sy', 'password' => 'Sara2026pw'])->assertOk()->assertJsonPath('user.portal', 'candidate');
    }

    public function test_confirmation_email_renders_reference(): void
    {
        $ref = $this->submit()->json('data.ref');
        $user = User::where('email', 'sara.ahmad@mail.sy')->first();
        $html = (string) (new ApplicationReceived($ref, 'محاسب', 'Accountant'))->toMail($user)->render();
        $this->assertStringContainsString($ref, $html);
        $this->assertStringContainsString('استلمنا طلبك على وظيفة محاسب', $html);
        $this->assertStringContainsString('We received your application for Accountant', $html);
    }

    public function test_incomplete_application_is_rejected_under_each_field(): void
    {
        $res = $this->submit(['name' => '', 'gender' => '', 'mobile' => '+963 ', 'email' => 'bad', 'institute' => '', 'salary' => 0, 'password' => '123', 'password_confirmation' => '1', 'agree_medical' => 0])
            ->assertStatus(422)->assertJsonPath('code', 'validation');
        foreach (['name', 'gender', 'mobile', 'email', 'institute', 'salary', 'password', 'agree_medical'] as $f) $this->assertArrayHasKey($f, $res->json('errors'), $f);
        $this->assertSame('هذا الحقل مطلوب', $res->json('errors.name.0'));
        $this->assertSame('Enter the full mobile number with country code', $this->submit(['mobile' => '0944'], 'en')->json('errors.mobile.0'));
        $this->assertSame(10, Candidate::count());
    }

    public function test_closed_or_unknown_job_is_refused(): void
    {
        $this->submit(['job' => 'REQ-2026-999'])->assertStatus(422)->assertJsonValidationErrors('job');
    }

    public function test_cv_type_and_size_are_checked_on_the_server(): void
    {
        $this->submit(['cv' => UploadedFile::fake()->create('cv.exe', 10, 'application/x-msdownload')])->assertStatus(422)->assertJsonValidationErrors('cv');
        $this->submit(['cv' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])->assertStatus(422)->assertJsonPath('errors.cv.0', 'الحد الأقصى لحجم الملف 10MB');
    }

    public function test_automation_gate_moves_strong_matches_to_screening(): void
    {
        $strong = $this->submit()->json('data.ref');
        $c = Candidate::where('ref', $strong)->first();
        $this->assertSame(['screening', 'passed'], [$c->stage, $c->auto_flag]);

        $weak = $this->submit(['email' => 'weak@mail.sy', 'national_id' => '09999', 'years_exp' => 0, 'degree' => 0, 'salary' => 2000, 'english' => 'weak', 'linkedin' => '', 'ref_name' => '', 'experience' => [], 'cv' => null])->json('data.ref');
        $w = Candidate::with('logs')->where('ref', $weak)->first();
        $this->assertSame(['new', 'below'], [$w->stage, $w->auto_flag]);
        $this->assertStringContainsString('دون حد المطابقة', $w->logs->last()->text_ar);
    }

    public function test_duplicate_requires_the_existing_account_password_then_merges(): void
    {
        $old = Candidate::where('email', 'cand1@mail.sy')->first();
        $this->postJson('/api/public/applications/check-duplicate', ['email' => 'CAND1@mail.sy', 'national_id' => ''])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('account', true)->assertJsonPath('blocked', false);

        $this->submit(['email' => 'cand1@mail.sy', 'password' => 'wrong-pass1', 'password_confirmation' => null])->assertStatus(422)->assertJsonPath('code', 'existing_account')->assertJsonValidationErrors('password');

        $this->submit(['email' => 'cand1@mail.sy', 'password' => '123456', 'password_confirmation' => null])->assertCreated()->assertJsonPath('data.ref', $old->ref)->assertJsonPath('data.merged', true);
        $old->refresh()->load('logs', 'jobOpening');
        $this->assertSame('REQ-2026-034', $old->jobOpening->code);
        $this->assertTrue($old->logs->contains(fn ($l) => $l->kind === 'warn' && str_contains($l->text_ar, 'أُلحق بالملف السابق')));
        $this->assertSame(10, Candidate::count());
    }

    public function test_same_national_id_with_new_email_moves_the_login_after_proof(): void
    {
        $old = Candidate::where('email', 'cand2@mail.sy')->first();
        $this->submit(['email' => 'raghad.new@mail.sy', 'national_id' => $old->national_id, 'password' => '123456', 'password_confirmation' => null])->assertCreated()->assertJsonPath('data.ref', $old->ref);
        $this->assertSame('raghad.new@mail.sy', User::where('candidate_id', $old->id)->value('email'));
    }

    public function test_application_at_offer_stage_cannot_be_overwritten(): void
    {
        $this->postJson('/api/public/applications/check-duplicate', ['email' => 'leen.ali@mail.sy'])->assertOk()->assertJsonPath('blocked', true)->assertJsonPath('code', 'application_in_progress');
        $this->submit(['email' => 'leen.ali@mail.sy', 'password' => '123456', 'password_confirmation' => null])->assertStatus(409)->assertJsonPath('code', 'application_in_progress');
    }

    public function test_employee_email_is_sent_to_internal_application(): void
    {
        $this->postJson('/api/public/applications/check-duplicate', ['email' => 'samer.halabi@alfarada.sy'])->assertJsonPath('code', 'staff_email')->assertJsonPath('field', 'email');
        $this->submit(['email' => 'samer.halabi@alfarada.sy'])->assertStatus(422)->assertJsonPath('code', 'staff_email');
        $this->assertNull(User::where('email', 'samer.halabi@alfarada.sy')->value('candidate_id'));
    }

    public function test_referral_code_links_referrer_and_notifies_them(): void
    {
        $this->submit(['source' => 'referral', 'ref_code' => 'UHR1025-JX'])->assertStatus(422)->assertJsonValidationErrors('ref_code');

        $ref = $this->submit(['source' => 'referral', 'ref_code' => 'uhr1025-j4'])->assertCreated()->json('data.ref');
        $c = Candidate::with('referrer')->where('ref', $ref)->first();
        $this->assertSame('UHR-1025', $c->referrer->code);
        $this->assertSame('UHR1025-J4', $c->ref_code);
        $r = Referral::where('code', 'UHR1025-J4')->first();
        $this->assertSame(['applied', $c->id], [$r->status, $r->candidate_id]);
        $this->assertTrue(Notice::where('audience', 'user')->where('user_id', User::where('employee_id', $c->referrer_id)->value('id'))->where('text_ar', 'سارة محمود الأحمد قدّم عبر رابط إحالتك')->exists());
    }

    public function test_public_portal_is_rate_limited(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/public/applications', [])->assertStatus(422);
        $this->postJson('/api/public/applications', [])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }
}
