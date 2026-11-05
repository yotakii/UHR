<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\Notice;
use App\Models\OfferApproval;
use App\Notifications\CandidateMessage;
use App\Services\Recruitment\F25;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** دفعة ملاحظات الاختبار 15b — التوظيف: أنواع الدوام والفلاتر، معاينة الروابط، أسئلة المقابلة، ميزانية العرض، تفاصيل الراتب، مطابقة بنك السير */
class RecruitmentNotesTest extends TestCase
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

    private function ratings(int $v = 4): array { return array_fill_keys(F25::keys(), $v); }

    /** 07، 08، 09: مكان العمل، شرائح الخبرة، الراتب في البوابة */
    public function test_portal_filters_by_workplace_experience_band_and_salary(): void
    {
        $job = JobOpening::allCompanies()->onPortal()->orderBy('id')->firstOrFail();
        JobOpening::allCompanies()->whereKey($job->id)->update(['workplace' => 'hybrid', 'min_years' => 2, 'salary_max' => 900, 'currency' => 'USD']);
        $p = $this->getJson('/api/public/portal')->assertOk();
        $this->assertContains('hybrid', array_column($p->json('filters.workplaces'), 'code'));
        $codes = fn ($q) => array_column($this->getJson('/api/public/jobs?'.$q)->assertOk()->json('data'), 'code');
        $this->assertSame([$job->code], $codes('workplace=hybrid'));
        $this->assertContains($job->code, $codes('exp=1-3'));
        $this->assertNotContains($job->code, $codes('exp=5%2B'));
        $this->assertContains($job->code, $codes('salary=800&currency=USD'));
        $this->assertNotContains($job->code, $codes('salary=1200&currency=USD'));
        $this->assertSame('hybrid', $this->getJson('/api/public/jobs?workplace=hybrid')->json('data.0.workplace.code'));
    }

    /** 33: معاينة الرابط — وسوم og وصورة عريضة 1200×630 */
    public function test_share_preview_tags_and_wide_image(): void
    {
        $job = JobOpening::allCompanies()->with('company')->onPortal()->orderBy('id')->firstOrFail();
        $html = $this->get('/api/public/og?path=/jobs/'.$job->company->slug.'/'.rawurlencode($job->code))->assertOk()->getContent();
        $this->assertStringContainsString('property="og:title" content="'.e($job->title_ar), $html);
        $this->assertStringContainsString('/api/public/og-image/'.$job->company->slug.'.png', $html);
        $img = $this->get('/api/public/og-image/'.$job->company->slug.'.png')->assertOk();
        [$w, $h] = getimagesizefromstring(file_get_contents($img->baseResponse->getFile()->getPathname()));
        $this->assertSame([1200, 630], [$w, $h]);
        $this->assertStringContainsString('og:image', $this->get('/api/public/og?path=/')->getContent());
        $this->get('/api/public/og-image/nope.png')->assertNotFound();
    }

    /** 30 و58: أسئلة الشاغر ومهاراته في F-25 — مع تبديل السؤال — والنتيجة الرسمية لا تتغير */
    public function test_job_interview_questions_and_skills_are_recorded(): void
    {
        $this->login('hr@alfarada.sy');
        $c = Candidate::where('stage', 'interview')->firstOrFail();
        JobOpening::whereKey($c->job_opening_id)->update(['interview' => json_encode(['questions' => [['ar' => 'لماذا هذه الوظيفة؟', 'en' => 'Why this job?']], 'skills' => [['ar' => 'Excel', 'en' => 'Excel']]])]);
        $plan = $this->getJson("/api/hr/candidates/{$c->id}")->assertOk()->json('data.interview_plan');
        $this->assertSame(['لماذا هذه الوظيفة؟', 'Excel', true], [$plan['questions'][0]['ar'], $plan['skills'][0]['ar'], $plan['from_job']]);
        $this->assertNotEmpty($plan['bank']);
        $r = $this->putJson("/api/hr/candidates/{$c->id}/interview", ['ratings' => $this->ratings(), 'recommendation' => 'recommended',
            'answers' => ['questions' => [['q' => 'سؤال بديل من البنك', 'note' => 'إجابة واضحة', 'score' => 4]], 'skills' => [['s' => 'Excel', 'score' => 5]]]])->assertOk();
        $this->assertSame(['سؤال بديل من البنك', 5, 80], [$r->json('data.interview.answers.questions.0.q'), $r->json('data.interview.answers.skills.0.score'), $r->json('data.interview.pct')]);

        // بنك الشركة يُحفظ من صفحة القواعد ويُستخدم للشاغر بلا أسئلة خاصة
        $rules = $this->getJson('/api/hr/recruitment-rules')->assertOk()->json();
        $this->putJson('/api/hr/recruitment-rules', ['rules' => $rules['rules'], 'stage_sla' => $rules['stage_sla'], 'interview_bank' => ['questions' => [['ar' => 'سؤال الشركة', 'en' => '']], 'skills' => []]])->assertOk();
        JobOpening::whereKey($c->job_opening_id)->update(['interview' => null]);
        $this->assertSame('سؤال الشركة', $this->getJson("/api/hr/candidates/{$c->id}")->json('data.interview_plan.questions.0.ar'));
    }

    /** 56 و70: لا عرض دون مقابلة موصى بها، والعرض فوق الميزانية ينتظر موافقة المدير العام ولا يراه المتقدم قبلها */
    public function test_offer_needs_interview_and_over_budget_needs_gm_approval(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $c = Candidate::where('stage', 'technical')->whereNotNull('user_id')->firstOrFail();
        $c->interview()->delete();
        $job = $c->jobOpening;
        $offer = ['title' => 'محاسب', 'contract' => 'fixed', 'currency' => $job->currency, 'basic' => (float) $job->salary_max, 'housing' => 200, 'transport' => 0, 'fuel' => 0, 'leave_days' => 14, 'start_date' => '2026-11-01'];
        $this->unlockSalaries(false);
        $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer)->assertStatus(409)->assertJsonPath('code', 'interview_required');
        $this->putJson("/api/hr/candidates/{$c->id}/interview", ['ratings' => $this->ratings(2), 'recommendation' => 'rejected'])->assertOk();
        $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer)->assertStatus(409)->assertJsonPath('code', 'interview_required');
        $this->putJson("/api/hr/candidates/{$c->id}/interview", ['ratings' => $this->ratings(), 'recommendation' => 'reserve'])->assertOk();

        // فوق الميزانية: المبرر إلزامي، ثم ينتظر الموافقة ولا يصل للمتقدم
        $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer)->assertStatus(422)->assertJsonValidationErrors('reason');
        $r = $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer + ['budget_reason' => 'خبرة نادرة وعرض منافس'])->assertOk();
        $this->assertSame(['pending_approval', 'technical'], [$r->json('data.offer.status'), $r->json('data.stage')]);
        Notification::assertNothingSentTo($c->user, CandidateMessage::class);
        $this->assertNull($this->actingAs($c->user)->getJson("/api/me/applications/{$c->id}")->json('data.offer'));

        // المدير العام يوافق من صندوق الاعتماد ← يُرسل العرض
        $gm = \App\Models\User::whereHas('employee', fn ($q) => $q->where('code', 'UHR-1001'))->firstOrFail();
        $this->login($gm->email);
        $this->unlockSalaries(false);
        $row = collect($this->getJson('/api/me/approvals')->assertOk()->json('data'))->firstWhere('kind', 'offer_budget');
        $this->assertSame([(float) $job->salary_max + 200, (float) $job->salary_max], [(float) $row['budget']['total'], (float) $row['budget']['max']]);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $c->refresh();
        $this->assertSame(['offer', 'sent'], [$c->stage, $c->offer->status]);
        Notification::assertSentTo($c->user, CandidateMessage::class, fn ($n) => $n->template === 'offer_sent');

        // سياسة «منع»: الحفظ مرفوض
        Tenant::run($c->company_id, fn () => Settings::put('rules', ['offer_budget' => 'block'] + Settings::get('rules')));
        $this->login('hr@alfarada.sy');
        $this->unlockSalaries(false);
        $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer + ['budget_reason' => 'خبرة نادرة وعرض منافس'])->assertStatus(422)->assertJsonValidationErrors('basic');
    }

    /** رفض تجاوز الميزانية يعيد العرض لفريق التوظيف */
    public function test_rejected_budget_returns_the_offer(): void
    {
        $this->login('hr@alfarada.sy');
        $c = Candidate::where('stage', 'technical')->whereNotNull('user_id')->firstOrFail();
        $this->putJson("/api/hr/candidates/{$c->id}/interview", ['ratings' => $this->ratings(), 'recommendation' => 'recommended'])->assertOk();
        $this->unlockSalaries(false);
        $job = $c->jobOpening;
        $this->putJson("/api/hr/candidates/{$c->id}/offer", ['title' => 'x', 'contract' => 'open', 'currency' => $job->currency, 'basic' => (float) $job->salary_max * 2, 'housing' => 0, 'transport' => 0, 'fuel' => 0,
            'leave_days' => 14, 'start_date' => '2026-11-01', 'budget_reason' => 'مبرر طويل بما يكفي'])->assertOk();
        $ap = OfferApproval::firstOrFail();
        $gm = \App\Models\User::whereHas('employee', fn ($q) => $q->where('code', 'UHR-1001'))->firstOrFail();
        $this->login($gm->email);
        $step = \App\Services\Workflow\Workflow::current($ap);
        $this->postJson("/api/me/approvals/{$step->id}", ['decision' => 'reject', 'note' => 'يتجاوز سقف الإدارة بكثير'])->assertOk();
        $this->assertSame(['rejected', 'returned'], [$ap->refresh()->status, $c->refresh()->offer->status]);
    }

    /** 59: إضافة موظف بالراتب المفصّل ونوع العقد */
    public function test_new_employee_keeps_pay_breakdown_and_contract(): void
    {
        $this->login('hr@alfarada.sy');
        $lk = $this->getJson('/api/hr/employees/lookups')->assertOk()->json();
        $r = $this->postJson('/api/hr/employees', ['name' => 'رامي الحسن', 'name_en' => 'Rami Hasan', 'gender' => 'M', 'national_id' => '01099887766', 'dob' => '1995-01-01', 'phone' => '+963 944 111 222',
            'email' => 'rami.hasan@alfarada.sy', 'department_id' => $lk['departments'][0]['id'], 'title_ar' => 'محاسب', 'title_en' => 'Accountant', 'location_id' => $lk['locations'][0]['id'],
            'currency' => 'USD', 'hire_date' => '2026-10-15', 'contract' => 'open', 'basic' => 600, 'housing' => 80, 'transport' => 40, 'fuel' => 0, 'asset_ids' => []])->assertCreated();
        $e = Employee::findOrFail($r->json('data.id'));
        $this->assertSame([720.0, 'open', 600.0], [$e->salary, $e->contract, (float) $e->pay['basic']]);
    }

    /** 46: سير القاعدة المطابقة لشاغر جديد + الدعوة مرة واحدة + إشعار الموارد البشرية عند النشر */
    public function test_cv_bank_matches_new_job_and_invites_once(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $src = Candidate::where('keep_cv', true)->where('cv_status', 'active')->orderBy('id')->firstOrFail();
        $lk = $this->getJson('/api/hr/jobs/lookups')->assertOk()->json();
        $job = $this->postJson('/api/hr/jobs', ['title_ar' => 'محلل', 'title_en' => 'Analyst', 'department_id' => $lk['departments'][0]['id'], 'location_id' => $lk['locations'][0]['id'],
            'employment_type' => 'full_time', 'workplace' => 'remote', 'openings' => 1, 'min_years' => 0, 'degree' => 0, 'salary_min' => 100, 'salary_max' => 5000, 'currency' => 'USD',
            'summary_ar' => 'ملخص', 'summary_en' => 'Summary', 'requirements' => [['ar' => 'متطلب', 'en' => 'Req']], 'visibility' => 'company', 'status' => 'open', 'hard_to_fill' => false, 'requires_photo' => false,
            'interview' => ['questions' => [['ar' => 'سؤال', 'en' => '']], 'skills' => []]])->assertCreated()->json('data');
        $this->assertSame('remote', JobOpening::find($job['id'])->workplace);
        $this->assertTrue(Notice::where('link', 'job-matches:'.$job['id'])->exists());
        $rows = $this->getJson("/api/hr/jobs/{$job['id']}/cv-matches")->assertOk()->json('data');
        $this->assertContains($src->id, array_column($rows, 'id'));
        $this->postJson("/api/hr/jobs/{$job['id']}/cv-matches/{$src->id}/invite")->assertOk();
        Notification::assertSentOnDemand(CandidateMessage::class, fn ($n) => $n->template === 'job_invite');
        $this->postJson("/api/hr/jobs/{$job['id']}/cv-matches/{$src->id}/invite")->assertStatus(409)->assertJsonPath('code', 'already_invited');
        $this->assertSame(1, AuditLog::where('action', 'cv.job_invite')->count());
        $this->assertTrue(collect($this->getJson("/api/hr/jobs/{$job['id']}/cv-matches")->json('data'))->firstWhere('id', $src->id)['invited']);
    }
}
