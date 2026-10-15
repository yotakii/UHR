<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\JobOpening;
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
        Storage::fake('public');
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    /** طلب كامل صالح — كل الحقول الإلزامية معبّأة */
    private function form(array $over = []): array
    {
        return array_replace([
            'name' => 'سارة محمود الأحمد', 'name_en' => 'Sara Al-Ahmad', 'gender' => 'F', 'marital' => 'single', 'nationality' => 'سورية',
            'dob' => '1997-03-14', 'pob' => 'دمشق', 'national_id' => '01020304050', 'blood' => 'O+', 'mobile' => '+963 944 555 123', 'email' => 'Sara.Ahmad@Mail.sy',
            'address' => 'دمشق — المزة', 'linkedin' => 'linkedin.com/in/sara',
            'degree' => 2, 'institute' => 'جامعة دمشق', 'major' => 'محاسبة', 'grad_year' => 2019, 'grade' => 'جيد جداً', 'english' => 'good', 'years_exp' => 4,
            'experience' => [['company' => 'بنك سورية الدولي', 'position' => 'محاسب', 'from' => 2020, 'to' => 2026, 'reason' => 'تطور مهني'], ['company' => '', 'position' => '']],
            'ref_name' => 'م. سليم حداد', 'ref_job' => 'مدير سابق', 'ref_phone' => '+963 944 120 330',
            'shifts' => 0, 'travel' => 1, 'public_sector' => 0, 'conviction' => 0, 'salary' => 750, 'availability' => '2026-11-01', 'source' => 'website',
            'cv' => UploadedFile::fake()->create('Sara_CV.pdf', 240, 'application/pdf'),
            'password' => 'Sara2026pw', 'password_confirmation' => 'Sara2026pw', 'agree_info' => 1, 'agree_medical' => 1, 'agree_data' => 1, 'keep_cv' => 1,
        ], $over);
    }

    private function submit(array $over = [], string $lang = 'ar', string $job = 'alfarada/REQ-2026-034')
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Accept-Language' => $lang])->post("/api/public/jobs/{$job}/apply", $this->form($over));
    }

    private function check(array $body, string $job = 'alfarada/REQ-2026-034')
    {
        return $this->withHeader('Accept-Language', 'ar')->postJson("/api/public/jobs/{$job}/check", $body);
    }

    public function test_complete_application_creates_file_account_and_notifies(): void
    {
        $res = $this->submit()->assertCreated()->assertJsonPath('data.email', 'sara.ahmad@mail.sy')->assertJsonPath('data.job.title.en', 'Accountant')->assertJsonPath('data.company.slug', 'alfarada');
        $ref = $res->json('data.ref');
        $this->assertMatchesRegularExpression('/^UNQ-2026-APP-\d{5}$/', $ref);

        $c = Candidate::with(['cv', 'logs', 'company'])->where('ref', $ref)->firstOrFail();
        $this->assertSame('alfarada', $c->company->slug);
        $this->assertNotNull($c->consent_at);
        $this->assertTrue($c->keep_cv);
        $this->assertSame($c->company_id, $c->cv->company_id);
        $this->assertSame('سارة محمود الأحمد', $c->name);
        $this->assertCount(1, $c->experience);                       // الخبرة الفارغة لا تُحفظ
        $this->assertSame('إجازة جامعية', $c->education[0]['phase']);
        $this->assertSame('Sara_CV.pdf', $c->cv->name);
        Storage::disk('local')->assertExists($c->cv->path);
        $this->assertGreaterThan(0, $c->match_score);
        $this->assertSame('F-28', $c->logs->first()->form);

        $user = User::with('roles')->where('email', 'sara.ahmad@mail.sy')->firstOrFail();
        $this->assertSame($user->id, $c->user_id);
        $this->assertSame(['candidate', null], [$user->portal(), $user->company_id]);
        $this->assertTrue(Notice::where('audience', 'hr')->where('link', "cand:{$c->id}")->where('text_ar', 'like', 'طلب توظيف جديد من سارة%')->exists());
        Notification::assertSentTo($user, ApplicationReceived::class, fn ($n) => $n->ref === $ref);

        // «متابعة حالة الطلب»: الدخول بنفس البيانات يفتح بوابة المتقدم
        $this->postJson('/api/auth/login', ['email' => 'sara.ahmad@mail.sy', 'password' => 'Sara2026pw'])->assertOk()->assertJsonPath('user.portal', 'candidate');
    }

    public function test_confirmation_email_renders_reference(): void
    {
        $ref = $this->submit()->json('data.ref');
        $user = User::where('email', 'sara.ahmad@mail.sy')->first();
        $html = (string) (new ApplicationReceived($ref, 'محاسب', 'Accountant', 'الفرادة', 'Uniqueness'))->toMail($user)->render();
        $this->assertStringContainsString($ref, $html);
        $this->assertStringContainsString('استلمنا طلبك على وظيفة محاسب', $html);
        $this->assertStringContainsString('We received your application for Accountant', $html);
    }

    public function test_incomplete_application_is_rejected_under_each_field(): void
    {
        $res = $this->submit(['name' => '', 'gender' => '', 'mobile' => '+963 ', 'email' => 'bad', 'institute' => '', 'salary' => 0, 'password' => '123', 'password_confirmation' => '1', 'agree_medical' => 0, 'agree_data' => 0])
            ->assertStatus(422)->assertJsonPath('code', 'validation');
        foreach (['name', 'gender', 'mobile', 'email', 'institute', 'salary', 'password', 'agree_medical', 'agree_data'] as $f) $this->assertArrayHasKey($f, $res->json('errors'), $f);
        $this->assertSame('هذا الحقل مطلوب', $res->json('errors.name.0'));
        $this->assertSame('Enter the full mobile number with country code', $this->submit(['mobile' => '0944'], 'en')->json('errors.mobile.0'));
        $this->assertSame(10, Candidate::count());
    }

    public function test_closed_expired_internal_or_unknown_job_is_refused(): void
    {
        $this->submit([], 'ar', 'alfarada/REQ-2026-999')->assertNotFound();
        $this->submit([], 'ar', 'atmata/REQ-2026-034')->assertNotFound();   // الرمز لشركة أخرى
        JobOpening::where('code', 'REQ-2026-034')->update(['closes_at' => '2026-10-11']);
        $this->submit()->assertNotFound()->assertJsonPath('message', 'هذه الوظيفة غير متاحة للتقديم حالياً — ربما أُغلقت أو انتهى موعدها.');
        JobOpening::where('code', 'REQ-2026-034')->update(['closes_at' => null, 'visibility' => 'internal']);
        $this->submit()->assertNotFound();
        JobOpening::where('code', 'REQ-2026-034')->update(['visibility' => 'company', 'status' => 'closed']);
        $this->submit()->assertNotFound();
    }

    public function test_cv_type_and_size_are_checked_on_the_server(): void
    {
        $this->submit(['cv' => UploadedFile::fake()->create('cv.exe', 10, 'application/x-msdownload')])->assertStatus(422)->assertJsonValidationErrors('cv');
        $this->submit(['cv' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])->assertStatus(422)->assertJsonPath('errors.cv.0', 'الحد الأقصى لحجم الملف 10MB');
    }

    public function test_match_bands_screen_review_or_decline(): void
    {
        $strong = $this->submit()->json('data.ref');
        $c = Candidate::where('ref', $strong)->first();
        $this->assertSame(['screening', 'passed', 'normal'], [$c->stage, $c->auto_flag, $c->match_band]);

        // 41–49 ← قائمة «مطابقة منخفضة» للمراجعة
        $mid = $this->submit(['email' => 'mid@mail.sy', 'national_id' => '07777', 'years_exp' => 1, 'degree' => 2, 'salary' => 900, 'english' => 'fair', 'linkedin' => '', 'experience' => [], 'cv' => null])->json('data.ref');
        $m = Candidate::with('logs')->where('ref', $mid)->first();
        $this->assertGreaterThanOrEqual(41, $m->match_score);
        $this->assertLessThanOrEqual(49, $m->match_score);
        $this->assertSame(['new', 'below', 'review'], [$m->stage, $m->auto_flag, $m->match_band]);
        $this->assertStringContainsString('مطابقة منخفضة', $m->logs->last()->text_ar);

        // ≤ 40 ← رفض تلقائي بسبب، وإشعار مهذب للمتقدم
        $weak = $this->submit(['email' => 'weak@mail.sy', 'national_id' => '09999', 'years_exp' => 0, 'degree' => 0, 'salary' => 2000, 'english' => 'weak', 'linkedin' => '', 'ref_name' => '', 'experience' => [], 'cv' => null])->json('data.ref');
        $w = Candidate::where('ref', $weak)->first();
        $this->assertSame(['rejected', 'reject', 'المطابقة دون الحد الأدنى'], [$w->stage, $w->match_band, $w->reject_reason]);
        Notification::assertSentTo(User::where('email', 'weak@mail.sy')->first(), \App\Notifications\ApplicationClosed::class);

        // الرفض التلقائي قابل للتعطيل ← يصير للمراجعة
        \App\Support\Tenant::run(\App\Models\Company::where('slug', 'alfarada')->first(), fn () => \App\Support\Settings::put('rules', [...\App\Support\Settings::get('rules'), 'auto_reject' => false]));
        $w2 = $this->submit(['email' => 'weak2@mail.sy', 'national_id' => '09998', 'years_exp' => 0, 'degree' => 0, 'salary' => 2000, 'english' => 'weak', 'linkedin' => '', 'ref_name' => '', 'experience' => [], 'cv' => null])->json('data.ref');
        $this->assertSame(['new', 'review'], [Candidate::where('ref', $w2)->value('stage'), Candidate::where('ref', $w2)->value('match_band')]);
    }

    public function test_photo_is_required_only_when_the_job_asks_for_it(): void
    {
        JobOpening::where('code', 'REQ-2026-034')->update(['requires_photo' => true]);
        $this->submit()->assertStatus(422)->assertJsonPath('errors.photo.0', 'هذه الوظيفة تتطلب صورة شخصية');
        $this->submit(['photo' => UploadedFile::fake()->create('me.gif', 50, 'image/gif')])->assertStatus(422)->assertJsonValidationErrors('photo');
        $ref = $this->submit(['photo' => UploadedFile::fake()->image('me.jpg', 300, 400)])->assertCreated()->json('data.ref');
        $this->assertNotNull(Candidate::where('ref', $ref)->value('photo_file_id'));
    }

    public function test_duplicate_requires_the_existing_account_password_then_merges(): void
    {
        $old = Candidate::where('email', 'cand1@mail.sy')->first();
        $this->check(['email' => 'CAND1@mail.sy', 'national_id' => ''])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('account', true)->assertJsonPath('blocked', false);

        $this->submit(['email' => 'cand1@mail.sy', 'password' => 'wrong-pass1', 'password_confirmation' => null])->assertStatus(422)->assertJsonPath('code', 'existing_account')->assertJsonValidationErrors('password');

        $this->submit(['email' => 'cand1@mail.sy', 'password' => '123456', 'password_confirmation' => null])->assertCreated()->assertJsonPath('data.ref', $old->ref)->assertJsonPath('data.merged', true);
        $old->refresh()->load('logs', 'jobOpening');
        $this->assertSame('REQ-2026-034', $old->jobOpening->code);
        $this->assertTrue($old->logs->contains(fn ($l) => $l->kind === 'warn' && str_contains($l->text_ar, 'أُلحق بالملف السابق')));
        $this->assertSame(10, Candidate::count());
    }

    public function test_national_id_of_another_account_is_refused(): void
    {
        $old = Candidate::where('email', 'cand2@mail.sy')->first();
        $this->submit(['email' => 'raghad.new@mail.sy', 'national_id' => $old->national_id])->assertStatus(422)->assertJsonPath('code', 'nid_taken')->assertJsonValidationErrors('national_id');
        $this->check(['email' => 'raghad.new@mail.sy', 'national_id' => $old->national_id])->assertJsonPath('blocked', true)->assertJsonPath('field', 'national_id');
    }

    public function test_one_account_applies_to_several_companies(): void
    {
        $this->submit()->assertCreated();
        // نفس البريد لشركة أخرى: حساب موجود ← كلمة مروره، وطلب مستقل داخل الشركة الثانية
        $this->check(['email' => 'sara.ahmad@mail.sy'], 'atmata/ATM-2026-002')->assertJsonPath('duplicate', false)->assertJsonPath('account', true);
        $this->submit(['password' => 'wrong', 'password_confirmation' => null], 'ar', 'atmata/ATM-2026-002')->assertStatus(422)->assertJsonPath('code', 'existing_account');
        $this->submit(['password' => 'Sara2026pw', 'password_confirmation' => null], 'ar', 'atmata/ATM-2026-002')->assertCreated()->assertJsonPath('data.company.slug', 'atmata')->assertJsonPath('data.merged', false);
        $user = User::where('email', 'sara.ahmad@mail.sy')->first();
        $this->assertSame(1, User::where('email', 'sara.ahmad@mail.sy')->count());
        $this->assertSame(['alfarada', 'atmata'], $user->candidates()->with('company')->orderBy('id')->get()->pluck('company.slug')->all());

        // المسجّل دخوله يقدّم بلا كلمة مرور، وببريد حسابه
        $this->postJson('/api/auth/login', ['email' => 'sara.ahmad@mail.sy', 'password' => 'Sara2026pw'])->assertOk();
        $this->check(['email' => 'other@x.sy'], 'atmata/ATM-2026-001')->assertJsonPath('signed_in', true)->assertJsonPath('duplicate', true);
        $this->submit(['email' => 'other@x.sy', 'password' => '', 'password_confirmation' => ''], 'ar', 'atmata/ATM-2026-001')->assertCreated()
            ->assertJsonPath('data.email', 'sara.ahmad@mail.sy')->assertJsonPath('data.merged', true);   // نفس الشركة ← يُلحق بطلبه السابق فيها
        $this->assertSame(2, $user->candidates()->count());
    }

    public function test_application_at_offer_stage_cannot_be_overwritten(): void
    {
        $this->check(['email' => 'leen.ali@mail.sy'])->assertOk()->assertJsonPath('blocked', true)->assertJsonPath('code', 'application_in_progress');
        $this->submit(['email' => 'leen.ali@mail.sy', 'password' => '123456', 'password_confirmation' => null])->assertStatus(409)->assertJsonPath('code', 'application_in_progress');
    }

    public function test_staff_emails_cannot_apply_publicly(): void
    {
        $this->check(['email' => 'samer.halabi@alfarada.sy'])->assertJsonPath('code', 'staff_email')->assertJsonPath('field', 'email')->assertJsonPath('message', 'هذا البريد مرتبط بحساب موظف في هذه الشركة. قدّم طلباً داخلياً من بوابة الموظف.');
        $this->submit(['email' => 'samer.halabi@alfarada.sy'])->assertStatus(422)->assertJsonPath('code', 'staff_email');
        $this->check(['email' => 'samer.halabi@alfarada.sy'], 'atmata/ATM-2026-001')->assertJsonPath('message', 'هذا البريد مرتبط بحساب عمل على المنصة. استخدم بريدك الشخصي للتقديم.');
        $this->assertSame(0, Candidate::allCompanies()->where('email', 'samer.halabi@alfarada.sy')->count());
    }

    public function test_existing_candidate_creates_no_referral(): void
    {
        // cand1 موجود مسبقاً لدى الفرادة ← لا إحالة جديدة (منع التحايل)
        $this->submit(['email' => 'cand1@mail.sy', 'password' => '123456', 'password_confirmation' => null, 'source' => 'referral', 'ref_code' => 'UHR1025-J4'])->assertCreated();
        $this->assertSame('shared', Referral::where('code', 'UHR1025-J4')->value('status'));
        $c1 = Candidate::with('referrer')->where('email', 'cand1@mail.sy')->first();
        $this->assertSame('UHR-1007', $c1->referrer->code);   // الإحالة الأصلية تبقى، ولا تُستبدل برابط جديد
    }

    public function test_referral_link_is_validated_on_the_job_page(): void
    {
        $this->getJson('/api/public/jobs/alfarada/REQ-2026-038?ref=uhr1025-j4')->assertOk()->assertJsonPath('data.referral.valid', true)->assertJsonPath('data.referral.code', 'UHR1025-J4');
        $this->getJson('/api/public/jobs/alfarada/REQ-2026-038?ref=NOPE-1')->assertOk()->assertJsonPath('data.referral.valid', false);
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
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/public/jobs/alfarada/REQ-2026-034/apply', [])->assertStatus(422);
        $this->postJson('/api/public/jobs/alfarada/REQ-2026-034/apply', [])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }
}
