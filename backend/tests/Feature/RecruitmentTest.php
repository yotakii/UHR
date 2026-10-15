<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ApplicationClosed;
use App\Notifications\CandidateMessage;
use App\Services\Recruitment\F25;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** M03 لوحة المتقدمين، M04 الملف (F-25، F-29)، وقرار التعيين F-31 حتى ملف الموظف */
class RecruitmentTest extends TestCase
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

    private function cand(string $email): Candidate
    {
        return Candidate::allCompanies()->where('email', $email)->firstOrFail();
    }

    /** حساب موظف الفرادة بالرقم الوظيفي */
    private function staff(string $code): string
    {
        return User::whereHas('employee', fn ($q) => $q->where('code', $code))->value('email');
    }

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function test_board_lists_company_candidates_by_permission(): void
    {
        $this->login('hr@alfarada.sy');
        $r = $this->getJson('/api/hr/candidates')->assertOk();
        $this->assertCount(10, $r->json('data'));
        $leen = collect($r->json('data'))->firstWhere('ref', $this->cand('leen.ali@mail.sy')->ref);
        $this->assertSame(['offer', 'sent', 'Accountant'], [$leen['stage'], $leen['offer'], $leen['job']['title']['en']]);
        $this->assertNotEmpty($r->json('jobs'));
        $this->assertCount(1, $this->getJson('/api/hr/candidates?q=leen')->json('data'));

        $this->login('jobs@atmata.sy');                  // مسؤول توظيف شركة أخرى
        $this->assertCount(0, $this->getJson('/api/hr/candidates')->json('data'));
        $this->getJson('/api/hr/candidates/'.$this->cand('leen.ali@mail.sy')->id)->assertNotFound();

        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/candidates')->assertForbidden();
    }

    public function test_stage_rules_reject_reason_and_interview_invite(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $c = Candidate::where('stage', 'new')->whereNotNull('user_id')->firstOrFail();
        $move = fn (string $to, ?string $reason = null) => $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => $to, 'reason' => $reason]);

        $move('hired')->assertStatus(409)->assertJsonPath('code', 'hire_via_decision');
        $move('offer')->assertStatus(409)->assertJsonPath('code', 'offer_required');
        $move('interview')->assertOk()->assertJsonPath('data.stage', 'interview');
        Notification::assertSentTo($c->user, CandidateMessage::class, fn ($n) => $n->template === 'interview_invite');

        $move('rejected', 'قصير')->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $move('rejected', 'لا تتوفر الخبرة المطلوبة للوظيفة')->assertOk()->assertJsonPath('data.stage', 'rejected')->assertJsonPath('data.reject_reason', 'لا تتوفر الخبرة المطلوبة للوظيفة');
        Notification::assertSentTo($c->user, ApplicationClosed::class);
        $this->assertTrue($c->logs()->where('kind', 'danger')->where('text_ar', 'like', '%لا تتوفر الخبرة%')->exists());

        // بعد فتح قرار التعيين لا نقل إلا عدم القبول
        $leen = $this->cand('leen.ali@mail.sy');
        \App\Support\Tenant::run($leen->company_id, fn () => app(\App\Services\Recruitment\OfferResponse::class)->openAppointment($leen));
        $this->postJson("/api/hr/candidates/{$leen->id}/move", ['stage' => 'technical'])->assertStatus(409)->assertJsonPath('code', 'locked_after_offer');
    }

    public function test_f25_interview_scores_and_moves_to_technical(): void
    {
        $this->login('hr@alfarada.sy');
        $c = Candidate::where('stage', 'interview')->firstOrFail();
        $ratings = array_fill_keys(F25::keys(), 4);
        $this->putJson("/api/hr/candidates/{$c->id}/interview", ['ratings' => array_slice($ratings, 0, 20), 'recommendation' => 'recommended'])->assertStatus(422);
        $r = $this->putJson("/api/hr/candidates/{$c->id}/interview", ['ratings' => $ratings, 'notes' => 'تواصل ممتاز', 'recommendation' => 'recommended'])->assertOk();
        $this->assertSame([80, 'technical', 'رشا الخطيب'], [$r->json('data.interview.pct'), $r->json('data.stage'), $r->json('data.interview.interviewer')]);
        $this->assertTrue($c->logs()->where('form', 'F-25')->exists());
    }

    public function test_offer_is_issued_to_the_applicant_and_locked_after_acceptance(): void
    {
        Notification::fake();
        $this->login('hr@alfarada.sy');
        $c = Candidate::where('stage', 'technical')->whereNotNull('user_id')->firstOrFail();
        $offer = ['title' => 'مهندس برمجيات', 'contract' => 'fixed', 'currency' => 'USD', 'basic' => 900, 'housing' => 100, 'transport' => 40, 'fuel' => 30, 'leave_days' => 14, 'start_date' => '2026-11-01'];
        $this->putJson("/api/hr/candidates/{$c->id}/offer", ['start_date' => '2026-01-01'] + $offer)->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer)->assertOk()->assertJsonPath('data.stage', 'offer')->assertJsonPath('data.offer.status', 'sent')->assertJsonPath('data.offer.total', 1070);
        Notification::assertSentTo($c->user, CandidateMessage::class, fn ($n) => $n->template === 'offer_sent');

        $leen = $this->cand('leen.ali@mail.sy');
        $leen->offer->update(['status' => 'accepted']);
        $this->putJson("/api/hr/candidates/{$leen->id}/offer", $offer)->assertStatus(409)->assertJsonPath('code', 'offer_locked');
    }

    public function test_appointment_levels_sign_from_their_own_accounts_then_employee_is_created(): void
    {
        Notification::fake();
        $leen = $this->cand('leen.ali@mail.sy');
        // المتقدمة تقبل وتوقّع من بوابتها ← يُفتح F-31
        $this->login('leen.ali@mail.sy');
        $this->postJson("/api/me/applications/{$leen->id}/offer-response", ['status' => 'accepted', 'signature' => self::signature()])->assertOk();
        $ap = $leen->refresh()->appointment()->with('approvals')->firstOrFail();
        $this->assertSame(['Human Resources', 'Finance', 'Executive Director', 'CEO'], $ap->approvals->pluck('role_en')->all());
        $steps = $ap->approvals->pluck('id')->all();

        // مستوى ليس دوره، ومستخدم ليس صاحبه
        $this->login($this->staff('UHR-1004'));          // المالية قبل الموارد البشرية
        $this->assertCount(0, $this->getJson('/api/hr/approvals')->json('data'));
        $this->postJson("/api/hr/approvals/{$steps[1]}", ['decision' => 'approved'])->assertStatus(409)->assertJsonPath('code', 'not_current_level');
        $this->login('admin@alfarada.sy');               // مدير النظام لا يعتمد بدل أصحاب المستويات
        $this->postJson("/api/hr/approvals/{$steps[0]}", ['decision' => 'approved'])->assertForbidden();

        $this->login('hr@alfarada.sy');
        $this->assertCount(1, $this->getJson('/api/hr/approvals')->json('data'));
        $this->assertContains('approvals', collect($this->getJson('/api/hr/dashboard')->json('data.tasks'))->pluck('link')->all());
        $this->postJson("/api/hr/approvals/{$steps[0]}", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.current.role.en', 'Finance');
        foreach (['UHR-1004' => 1, 'UHR-1002' => 2] as $code => $i) {
            $this->login($this->staff($code));
            $this->assertCount(1, $this->getJson('/api/hr/approvals')->json('data'));
            $this->postJson("/api/hr/approvals/{$steps[$i]}", ['decision' => 'approved', 'reason' => 'مطابق للملاك'])->assertOk();
        }
        $cardsBefore = Asset::where('status', 'available')->whereHas('category', fn ($q) => $q->where('code', 'cards'))->count();
        $this->login($this->staff('UHR-1001'));          // الرئيس التنفيذي — المستوى الأخير
        $r = $this->postJson("/api/hr/approvals/{$steps[3]}", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.complete', true);

        $emp = Employee::findOrFail($r->json('data.employee_id'));
        $this->assertSame(['UHR-1033', 'onboarding', 'لين العلي', 800.0], [$emp->code, $emp->status, $emp->name, (float) $emp->salary]);
        $this->assertSame('hired', $leen->refresh()->stage);
        $u = User::where('email', 'leen.ali@mail.sy')->first();
        $this->assertSame([$emp->id, $leen->company_id, 'employee'], [$u->employee_id, $u->company_id, $u->portal()]);
        $this->assertSame($cardsBefore - 1, Asset::where('status', 'available')->whereHas('category', fn ($q) => $q->where('code', 'cards'))->count());
        $this->assertSame(1, Asset::where('employee_id', $emp->id)->where('status', 'pending_ack')->count());
        $this->assertTrue($emp->onboarding()->exists());
        $this->assertTrue($emp->logs()->where('form', 'F-31')->exists());
        Notification::assertSentTo($u, CandidateMessage::class, fn ($n) => $n->template === 'hired');
        $this->assertSame(4, AuditLog::where('action', 'appointment.approved')->count());
    }

    public function test_rejecting_a_level_needs_a_reason_and_closes_the_application(): void
    {
        $leen = $this->cand('leen.ali@mail.sy');
        $leen->offer->update(['status' => 'accepted']);
        $ap = \App\Support\Tenant::run($leen->company_id, fn () => app(\App\Services\Recruitment\OfferResponse::class)->openAppointment($leen));
        $first = $ap->approvals()->orderBy('level')->first();
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/approvals/{$first->id}", ['decision' => 'rejected', 'reason' => 'لا'])->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/hr/approvals/{$first->id}", ['decision' => 'rejected', 'reason' => 'الملاك العددي للإدارة مكتمل'])->assertOk()->assertJsonPath('data.rejected', true);
        $this->assertSame(['rejected', 'الملاك العددي للإدارة مكتمل'], [$leen->refresh()->stage, $leen->reject_reason]);
    }

    public function test_recruitment_rules_are_validated_and_audited(): void
    {
        $this->login('hr@alfarada.sy');
        $cur = $this->getJson('/api/hr/recruitment-rules')->assertOk()->json();
        $bad = $cur; $bad['rules']['review_min'] = 30;
        $this->putJson('/api/hr/recruitment-rules', $bad)->assertStatus(422)->assertJsonPath('code', 'bands_order');
        $ok = $cur; $ok['rules']['match_threshold'] = 60; $ok['stage_sla']['new'] = 3;
        $this->putJson('/api/hr/recruitment-rules', $ok)->assertOk()->assertJsonPath('rules.match_threshold', 60)->assertJsonPath('stage_sla.new', 3);
        $this->assertSame(1, AuditLog::where('action', 'settings.recruitment_rules')->count());

        $this->login('jobs@atmata.sy');
        $this->putJson('/api/hr/recruitment-rules', $ok)->assertForbidden();
    }
}
