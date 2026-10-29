<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateTest;
use App\Models\JobOpening;
use App\Notifications\CandidateMessage;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** الاختبار الأولي (D-34): HR يكتب الأسئلة ويعتمدها ← الإرسال ← الحل بمدة ← التصحيح والمراجعة — بلا رفض آلي */
class PreliminaryTestTest extends TestCase
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

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function job(): JobOpening { return JobOpening::allCompanies()->where('code', 'REQ-2026-034')->firstOrFail(); }

    private function prepare(string $audience = 'passed'): array
    {
        $this->login('hr@alfarada.sy');
        $id = $this->job()->id;
        $qs = [
            ['type' => 'mcq', 'text' => 'ما المعادلة المحاسبية الأساسية؟', 'options' => ['الأصول = الخصوم + حقوق الملكية', 'الأصول = الإيرادات', 'الخصوم = المصاريف'], 'answer' => 0, 'points' => 10, 'rubric' => ''],
            ['type' => 'mcq', 'text' => 'أي قائمة مالية تُظهر صافي الربح؟', 'options' => ['الميزانية', 'قائمة الدخل'], 'answer' => 1, 'points' => 10, 'rubric' => ''],
            ['type' => 'short', 'text' => 'اشرح مبدأ الاستحقاق.', 'options' => [], 'answer' => -1, 'points' => 15, 'rubric' => 'يذكر تسجيل الإيراد والمصروف عند حدوثهما'],
        ];
        $body = ['enabled' => true, 'audience' => $audience, 'minutes' => 20, 'attempts' => 2, 'valid_days' => 3, 'questions' => $qs, 'approve' => false];
        $r = $this->putJson("/api/hr/jobs/{$id}/test", $body)->assertOk();
        $this->assertSame('draft', $r->json('data.test.status'));
        $r = $this->putJson("/api/hr/jobs/{$id}/test", ['approve' => true, 'questions' => $r->json('data.test.questions')] + $body)->assertOk();
        $this->assertSame('approved', $r->json('data.test.status'));
        return $r->json('data.test.questions');
    }

    private function apply(array $over = []): Candidate
    {
        $this->postJson('/api/auth/logout');
        $form = array_replace([
            'name' => 'سارة محمود الأحمد', 'name_en' => 'Sara Al-Ahmad', 'gender' => 'F', 'marital' => 'single', 'nationality' => 'سورية', 'dob' => '1997-03-14', 'pob' => 'دمشق',
            'national_id' => '01020304050', 'blood' => 'O+', 'mobile' => '+963 944 555 123', 'email' => 'sara.ahmad@mail.sy', 'address' => 'دمشق — المزة', 'linkedin' => 'linkedin.com/in/sara',
            'degree' => 2, 'institute' => 'جامعة دمشق', 'major' => 'محاسبة', 'grad_year' => 2019, 'grade' => 'جيد جداً', 'english' => 'good', 'years_exp' => 4,
            'experience' => [['company' => 'بنك سورية الدولي', 'position' => 'محاسب', 'from' => 2020, 'to' => 2026, 'reason' => 'تطور مهني']],
            'ref_name' => 'م. سليم حداد', 'ref_job' => 'مدير سابق', 'ref_phone' => '+963 944 120 330', 'shifts' => 0, 'travel' => 1, 'public_sector' => 0, 'conviction' => 0, 'salary' => 750,
            'availability' => '2026-11-01', 'source' => 'website', 'cv' => UploadedFile::fake()->create('Sara_CV.pdf', 240, 'application/pdf'),
            'password' => 'Sara2026pw', 'password_confirmation' => 'Sara2026pw', 'agree_info' => 1, 'agree_medical' => 1, 'agree_data' => 1, 'keep_cv' => 1,
        ], $over);
        $ref = $this->withHeaders(['Accept' => 'application/json'])->post('/api/public/jobs/alfarada/REQ-2026-034/apply', $form)->assertCreated()->json('data.ref');
        return Candidate::allCompanies()->where('ref', $ref)->firstOrFail();
    }

    public function test_hr_writes_and_approves_the_questions(): void
    {
        $qs = $this->prepare();
        $this->assertCount(3, $qs);
        $this->assertNotEmpty($qs[0]['id']);
        $this->assertSame(1, AuditLog::where('action', 'test.approved')->count());
        // بلا أسئلة لا اعتماد، والمستخدم العادي لا يعدّل
        $this->putJson("/api/hr/jobs/{$this->job()->id}/test", ['enabled' => true, 'audience' => 'all', 'minutes' => 20, 'attempts' => 1, 'valid_days' => 3, 'questions' => [], 'approve' => true])
            ->assertStatus(422)->assertJsonPath('code', 'no_questions');
        $this->login('samer.halabi@alfarada.sy');
        $this->putJson("/api/hr/jobs/{$this->job()->id}/test", ['enabled' => true, 'audience' => 'all', 'minutes' => 20, 'attempts' => 1, 'valid_days' => 3, 'questions' => $qs, 'approve' => true])->assertForbidden();
    }

    public function test_applicant_gets_a_timed_link_answers_and_hr_reviews_without_auto_reject(): void
    {
        $qs = $this->prepare();
        $c = $this->apply();
        $ct = CandidateTest::allCompanies()->where('candidate_id', $c->id)->firstOrFail();
        Notification::assertSentTo($c->user, CandidateMessage::class, fn ($n) => $n->template === 'test_invite' && str_contains($n->path, $ct->token));

        // قبل البدء: لا أسئلة. بعد البدء: الأسئلة بلا الإجابات الصحيحة ولا المعيار
        $this->getJson('/api/public/tests/'.str_repeat('x', 48))->assertNotFound();
        $this->getJson("/api/public/tests/{$ct->token}")->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonCount(0, 'data.questions');
        $s = $this->postJson("/api/public/tests/{$ct->token}/start")->assertOk();
        $this->assertSame('started', $s->json('data.status'));
        $this->assertArrayNotHasKey('answer', $s->json('data.questions.0'));
        $this->assertArrayNotHasKey('rubric', $s->json('data.questions.2'));

        // إجابة: الأول صحيح، الثاني خطأ، المفتوح نص — 50٪ موضوعي، والمفتوح بانتظار HR
        $done = $this->postJson("/api/public/tests/{$ct->token}/submit", ['answers' => [$qs[0]['id'] => 0, $qs[1]['id'] => 0, $qs[2]['id'] => 'تسجيل الإيراد عند تحققه لا عند القبض']])->assertOk();
        $this->assertSame('completed', $done->json('data.status'));
        $this->postJson("/api/public/tests/{$ct->token}/submit", ['answers' => []])->assertStatus(409);
        $ct->refresh();
        $this->assertSame(50, $ct->auto_pct);
        $this->assertNotSame('rejected', $c->refresh()->stage);

        // مراجعة HR: درجة كل سؤال مفتوح مطلوبة ← النتيجة النهائية (10+0+12)/35
        $this->login('hr@alfarada.sy');
        $f = $this->getJson("/api/hr/candidates/{$c->id}")->assertOk();
        $this->assertSame('completed', $f->json('data.test_detail.current.status'));
        $this->postJson("/api/hr/candidates/{$c->id}/test/review", ['scores' => [], 'note' => 'مستوى جيد'])->assertStatus(422);
        $r = $this->postJson("/api/hr/candidates/{$c->id}/test/review", ['scores' => [$qs[2]['id'] => 12], 'note' => 'مستوى جيد'])->assertOk();
        $this->assertSame([63, 'reviewed'], [$r->json('data.test.pct'), $r->json('data.test.status')]);
        $this->assertSame(63, collect($this->getJson('/api/hr/candidates')->json('data'))->firstWhere('id', $c->id)['test']['pct']);
        $this->assertSame(1, AuditLog::where('action', 'test.reviewed')->count());
    }

    public function test_link_expires_as_incomplete_and_attempts_are_limited(): void
    {
        $this->prepare();
        $c = $this->apply();
        $ct = CandidateTest::allCompanies()->where('candidate_id', $c->id)->firstOrFail();
        $this->postJson("/api/public/tests/{$ct->token}/start")->assertOk();
        Carbon::setTestNow(now()->addMinutes(25));                     // المدة 20 دقيقة
        $this->postJson("/api/public/tests/{$ct->token}/submit", ['answers' => []])->assertStatus(410)->assertJsonPath('code', 'test_expired');
        $this->assertSame('expired', $ct->refresh()->status);

        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/candidates/{$c->id}/test")->assertOk()->assertJsonPath('data.test_detail.current.attempt', 2);
        $this->postJson("/api/hr/candidates/{$c->id}/test")->assertStatus(409)->assertJsonPath('code', 'test_open');
        Carbon::setTestNow(now()->addDays(4));                         // انتهت صلاحية الرابط الثاني
        $this->artisan('tests:expire')->assertSuccessful();
        $this->assertSame('expired', CandidateTest::allCompanies()->where('candidate_id', $c->id)->where('attempt', 2)->value('status'));
        $this->postJson("/api/hr/candidates/{$c->id}/test")->assertStatus(409)->assertJsonPath('code', 'no_attempts');
    }

    public function test_audience_passed_skips_low_match(): void
    {
        $this->prepare('passed');
        $low = $this->apply(['email' => 'mid@mail.sy', 'national_id' => '07777', 'years_exp' => 1, 'salary' => 900, 'english' => 'fair', 'linkedin' => '', 'experience' => [], 'cv' => null]);
        $this->assertSame('review', $low->match_band);
        $this->assertSame(0, CandidateTest::allCompanies()->where('candidate_id', $low->id)->count());

    }
}
