<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Notice;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** M05 — بوابة المتقدم: متابعة الطلب والرد على عرض العمل F-29 ← قرار التعيين F-31 */
class CandidatePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function asLeen(): static
    {
        $this->postJson('/api/auth/login', ['email' => 'leen.ali@mail.sy', 'password' => '123456'])->assertOk();
        return $this;
    }

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function respond(array $body)
    {
        return $this->postJson('/api/me/application/offer-response', $body);
    }

    public function test_applicant_sees_own_application_with_pending_offer(): void
    {
        $this->asLeen()->getJson('/api/me/application')->assertOk()
            ->assertJsonPath('data.name', 'لين العلي')->assertJsonPath('data.stage', 'offer')
            ->assertJsonPath('data.job.title.en', 'Accountant')->assertJsonPath('data.job.dept.en', 'Finance')
            ->assertJsonPath('data.offer.status', 'sent')->assertJsonPath('data.offer.total', 800)->assertJsonPath('data.offer.signature', null)
            ->assertJsonPath('data.appointment', null)->assertJsonPath('data.cv.name', 'Leen_Ali_CV.pdf')
            ->assertJsonMissingPath('data.match_score');
    }

    public function test_only_applicants_reach_the_portal(): void
    {
        $this->getJson('/api/me/application')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'hr@alfarada.sy', 'password' => '123456']);
        $this->getJson('/api/me/application')->assertForbidden();
    }

    public function test_accepting_requires_a_signature(): void
    {
        $this->asLeen()->respond(['status' => 'accepted'])->assertStatus(422)->assertJsonPath('code', 'signature_required')->assertJsonValidationErrors('signature');
        $this->respond(['status' => 'accepted', 'signature' => 'data:image/png;base64,AAAA'])->assertStatus(422);
        $this->assertSame('sent', Candidate::where('email', 'leen.ali@mail.sy')->first()->offer()->value('status'));
    }

    public function test_accepting_opens_the_appointment_decision_for_hr(): void
    {
        $res = $this->asLeen()->respond(['status' => 'accepted', 'signature' => self::signature()])->assertOk()
            ->assertJsonPath('data.offer.status', 'accepted')->assertJsonPath('data.appointment.complete', false);
        $this->assertStringStartsWith('data:image/png;base64,', $res->json('data.offer.signature'));
        $this->assertMatchesRegularExpression('/^QR-2026-\d+$/', $res->json('data.appointment.decision_no'));
        // سلسلة الاعتماد من الإعدادات: الموارد البشرية ← المالية ← التنفيذي ← الرئيس التنفيذي، بأسماء أصحابها
        $this->assertSame(['Human Resources', 'Finance', 'Executive Director', 'CEO'], array_column(array_column($res->json('data.appointment.approvals'), 'role'), 'en'));
        $this->assertSame('رشا الخطيب', $res->json('data.appointment.approvals.0.name'));
        $this->assertSame(['pending'], array_values(array_unique(array_column($res->json('data.appointment.approvals'), 'status'))));

        $c = Candidate::with('logs')->where('email', 'leen.ali@mail.sy')->first();
        $this->assertTrue($c->logs->contains(fn ($l) => $l->form === 'F-31'));
        $this->assertTrue(Notice::where('audience', 'hr')->where('link', "cand:{$c->id}")->where('text_ar', 'لين العلي وقّع عرض العمل — قرار التعيين بانتظار الاعتماد')->exists());

        // لا رد ثانٍ على نفس العرض
        $this->respond(['status' => 'declined'])->assertStatus(409)->assertJsonPath('code', 'offer_not_open');
    }

    public function test_negotiation_needs_the_requested_terms(): void
    {
        $this->asLeen()->respond(['status' => 'negotiation', 'note' => ' '])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->respond(['status' => 'negotiation', 'note' => 'زيادة بدل المواصلات'])->assertOk()
            ->assertJsonPath('data.offer.status', 'negotiation')->assertJsonPath('data.offer.note', 'زيادة بدل المواصلات')->assertJsonPath('data.appointment', null);
        $this->assertTrue(Notice::where('text_ar', 'لين العلي طلب تعديل بنود عرض العمل')->exists());
    }

    public function test_declining_is_logged_and_hr_is_told(): void
    {
        $this->asLeen()->respond(['status' => 'declined'])->assertOk()->assertJsonPath('data.offer.status', 'declined');
        $this->assertTrue(Notice::where('text_ar', 'لين العلي اعتذر عن عرض العمل')->exists());
    }

    public function test_applicant_without_offer_cannot_respond(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'cand1@mail.sy', 'password' => '123456'])->assertOk();
        $this->getJson('/api/me/application')->assertOk()->assertJsonPath('data.offer', null);
        $this->respond(['status' => 'declined'])->assertStatus(409);
    }
}
