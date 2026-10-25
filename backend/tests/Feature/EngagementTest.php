<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\InternalApplication;
use App\Models\JobOpening;
use App\Models\Notice;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** M12: روابط الإحالة والمتجر والاستبدال، الاستقطاب الداخلي F-27 (المدير فقط ← متقدم داخلي ← نقل يحدّث ملف الموظف)، والإعلانات (D-06) */
class EngagementTest extends TestCase
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
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function emp(string $code): Employee { return Employee::allCompanies()->where('code', $code)->firstOrFail(); }
    private function staff(string $code): string { return User::where('employee_id', $this->emp($code)->id)->value('email'); }
    private function job(string $code): JobOpening { return JobOpening::allCompanies()->where('code', $code)->firstOrFail(); }

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function test_referral_link_store_and_redemption(): void
    {
        $samer = $this->emp('UHR-1007');
        $job = $this->job('REQ-2026-031');
        $this->login('samer.halabi@alfarada.sy');
        $a = $this->postJson('/api/me/rewards/share', ['job_id' => $job->id])->assertOk()->json();
        $this->assertMatchesRegularExpression('/^UHR1007-J\d+$/', $a['code']);
        $this->assertStringContainsString('/jobs/alfarada/REQ-2026-031?ref='.$a['code'], $a['link']);
        $this->assertSame($a['code'], $this->postJson('/api/me/rewards/share', ['job_id' => $job->id])->json('code'));   // ثابت
        $job->update(['visibility' => 'internal']);
        $this->postJson('/api/me/rewards/share', ['job_id' => $job->id])->assertConflict()->assertJsonPath('code', 'not_shareable');
        $job->update(['visibility' => 'public']);
        $this->postJson('/api/auth/logout');
        $clicks = (int) Referral::allCompanies()->where('code', $a['code'])->value('clicks');
        $this->getJson("/api/public/jobs/alfarada/REQ-2026-031?ref={$a['code']}")->assertOk()->assertJsonPath('data.referral.valid', true);
        $this->getJson("/api/public/jobs/alfarada/REQ-2026-031?ref={$a['code']}")->assertOk();
        $this->assertSame($clicks + 1, Referral::allCompanies()->where('code', $a['code'])->value('clicks'));   // مرة لكل جلسة

        // الاستبدال: النقاط تُحجز، الرفض بسبب يعيدها، الاعتماد ينقص المخزون
        $samer->update(['points' => 1000, 'lifetime_points' => 1000]);
        $food = Reward::allCompanies()->where('company_id', $samer->company_id)->where('cost', 400)->firstOrFail();
        $course = Reward::allCompanies()->where('company_id', $samer->company_id)->where('cost', 2000)->firstOrFail();
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/me/rewards/redeem', ['reward_id' => $course->id])->assertConflict()->assertJsonPath('code', 'not_enough_points');
        $d = $this->postJson('/api/me/rewards/redeem', ['reward_id' => $food->id])->assertOk()->json();
        $this->assertSame(600, $d['points']);
        $this->postJson('/api/me/rewards/redeem', ['reward_id' => $food->id])->assertOk();
        $this->assertSame(200, $samer->fresh()->points);
        [$r1, $r2] = Redemption::allCompanies()->where('employee_id', $samer->id)->orderBy('id')->get()->all();

        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/referrals/redemptions/{$r1->id}", ['approve' => false, 'note' => 'قصير'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/hr/referrals/redemptions/{$r1->id}", ['approve' => false, 'note' => 'المطعم مغلق هذا الشهر'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(600, $samer->fresh()->points);
        $stock = $food->fresh()->stock;
        $this->postJson("/api/hr/referrals/redemptions/{$r2->id}", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame($stock - 1, $food->fresh()->stock);
        $this->postJson("/api/hr/referrals/redemptions/{$r2->id}", ['approve' => true])->assertConflict()->assertJsonPath('code', 'decided');

        $d = $this->getJson('/api/hr/referrals')->assertOk()->json();
        $this->assertSame(6, count($d['rewards']));
        $this->postJson('/api/hr/referrals/rewards', ['title_ar' => 'ساعة ذكية', 'title_en' => 'Smart watch', 'cost' => 0, 'stock' => 3, 'kind' => 'gift'])->assertUnprocessable()->assertJsonValidationErrors('cost');
        $this->postJson('/api/hr/referrals/rewards', ['title_ar' => 'ساعة ذكية', 'title_en' => 'Smart watch', 'cost' => 1800, 'stock' => 3, 'kind' => 'gift'])->assertCreated();
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/hr/referrals/rewards', ['title_ar' => 'x', 'title_en' => 'x', 'cost' => 1, 'stock' => 1, 'kind' => 'gift'])->assertForbidden();
    }

    public function test_internal_application_goes_to_the_manager_only_and_the_move_updates_the_employee(): void
    {
        Notification::fake();
        $samer = $this->emp('UHR-1007');
        $job = $this->job('REQ-2026-034');   // إدارة أخرى
        $this->login('samer.halabi@alfarada.sy');
        $d = $this->getJson('/api/me/internal')->assertOk()->json();
        $this->assertTrue($d['eligibility']['ok']);
        $this->postJson('/api/me/internal', ['job_id' => $job->id, 'reason' => 'الرغبة في التطور المهني'])->assertOk()->assertJsonPath('data.0.status', 'pending_manager');
        $this->postJson('/api/me/internal', ['job_id' => $job->id, 'reason' => 'مرة ثانية'])->assertConflict()->assertJsonPath('code', 'already_applied');
        $ia = InternalApplication::allCompanies()->where('employee_id', $samer->id)->firstOrFail();

        // لا يظهر لـ HR قبل قبول المدير
        $this->login('hr@alfarada.sy');
        $this->assertFalse(collect($this->getJson('/api/me/approvals')->json('data'))->contains('kind', 'internal'));
        $this->assertFalse(Candidate::allCompanies()->where('internal_employee_id', $samer->id)->exists());

        $this->login('alaa.sheikhani@alfarada.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'internal');
        $this->assertSame($job->id, $row['job']['id']);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve', 'note' => 'أؤيد الطلب'])->assertOk();
        $c = Candidate::allCompanies()->where('internal_employee_id', $samer->id)->firstOrFail();
        $this->assertSame(['screening', 'internal'], [$c->stage, $c->source]);
        $this->assertStringContainsString('-INT-', $c->ref);
        $this->assertSame($c->id, $ia->fresh()->candidate_id);
        $this->assertTrue(Notice::allCompanies()->where('link', 'cand:'.$c->id)->exists());

        // العرض يرد عليه الموظف من بوابته، ثم F-31 ← النقل يحدّث ملفه (لا موظف جديد)
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/candidates/{$c->id}/move", ['stage' => 'technical'])->assertOk();
        $offer = ['title' => 'محاسب أول', 'contract' => 'fixed', 'currency' => 'USD', 'basic' => 1200, 'housing' => 100, 'transport' => 40, 'fuel' => 30, 'leave_days' => 21, 'start_date' => '2026-11-01'];
        $this->putJson("/api/hr/candidates/{$c->id}/offer", $offer)->assertOk();
        $this->login('samer.halabi@alfarada.sy');
        $this->assertSame('sent', $this->getJson('/api/me/internal')->json('data.0.offer.status'));
        $this->postJson("/api/me/internal/{$ia->id}/offer-response", ['status' => 'accepted', 'signature' => self::signature()])->assertOk();
        $steps = $c->refresh()->appointment()->with('approvals')->firstOrFail()->approvals->sortBy('level')->pluck('id')->values()->all();
        $count = Employee::allCompanies()->count();
        foreach (['hr@alfarada.sy', $this->staff('UHR-1004'), $this->staff('UHR-1002'), $this->staff('UHR-1001')] as $i => $email) {
            $this->login($email);
            $this->postJson("/api/hr/approvals/{$steps[$i]}", ['decision' => 'approved', 'reason' => 'مطابق للملاك'])->assertOk();
        }
        $this->assertSame($count, Employee::allCompanies()->count());
        $s = $samer->fresh();
        $this->assertSame(['محاسب أول', $job->department_id], [$s->title_ar, $s->department_id]);
        $this->assertEquals(1370, (float) $s->salary);
        $this->assertSame('hired', $c->fresh()->stage);
    }

    public function test_internal_eligibility_rules(): void
    {
        $samer = $this->emp('UHR-1007');
        $samer->update(['hire_date' => now()->subMonths(5)->toDateString()]);
        $this->login('samer.halabi@alfarada.sy');
        $d = $this->getJson('/api/me/internal')->json('eligibility');
        $this->assertFalse($d['ok']);
        $this->postJson('/api/me/internal', ['job_id' => $this->job('REQ-2026-034')->id, 'reason' => 'الرغبة في التطور'])->assertUnprocessable()->assertJsonPath('code', 'not_eligible');
        $this->login('hr@alfarada.sy');
        $cur = $this->getJson('/api/hr/recruitment-rules')->json();
        $this->putJson('/api/hr/recruitment-rules', ['rules' => ['internal_min_months' => 3] + $cur['rules'], 'stage_sla' => $cur['stage_sla']])->assertOk();
        $this->login('samer.halabi@alfarada.sy');
        $this->assertTrue($this->getJson('/api/me/internal')->json('eligibility.ok'));
    }

    public function test_announcements_audience_approval_and_expiry(): void
    {
        $this->login('hr@alfarada.sy');
        $it = $this->emp('UHR-1007')->department_id;
        $a = $this->post('/api/hr/announcements', ['title_ar' => 'صيانة الشبكة', 'text_ar' => 'تنقطع الشبكة مساء الخميس لساعتين.', 'tag' => 'people', 'audience' => 'department', 'department_id' => $it,
            'expires_at' => '2026-10-20 00:00:00', 'image' => UploadedFile::fake()->image('net.png', 400, 200)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame('published', $a['status']);
        $this->assertNotNull($a['image']);
        $samerUser = User::where('email', 'samer.halabi@alfarada.sy')->value('id');
        $this->assertTrue(Notice::allCompanies()->where('user_id', $samerUser)->where('link', 'announcements')->exists());
        $this->assertFalse(Notice::allCompanies()->where('user_id', User::where('email', $this->staff('UHR-1004'))->value('id'))->where('link', 'announcements')->exists());

        $this->login('samer.halabi@alfarada.sy');
        $this->assertContains('صيانة الشبكة', collect($this->getJson('/api/me/announcements')->json('data'))->pluck('title.ar')->all());
        $this->get("/api/files/{$a['image']}")->assertOk();
        $this->login($this->staff('UHR-1004'));   // إدارة أخرى
        $this->assertNotContains('صيانة الشبكة', collect($this->getJson('/api/me/announcements')->json('data'))->pluck('title.ar')->all());
        $this->get("/api/files/{$a['image']}")->assertForbidden();

        // الاعتماد قبل النشر (إعداد): الأخصائي يكتب، والمديرة تعتمد
        $this->login('hr@alfarada.sy');
        $this->putJson('/api/hr/announcements/settings', ['require_approval' => true, 'max_mb' => 10])->assertOk();
        $this->login($this->staff('UHR-1005') ?: 'nour.hassan@alfarada.sy');
        $officer = User::where('email', 'nour.hassan@alfarada.sy')->firstOrFail();
        $this->login($officer->email);
        $p = $this->postJson('/api/hr/announcements', ['title_ar' => 'دورة تدريبية', 'text_ar' => 'دورة إكسل متقدم الأسبوع القادم.', 'tag' => 'training', 'audience' => 'all'])->assertCreated()->json('data');
        $this->assertSame('pending', $p['status']);
        $this->postJson("/api/hr/announcements/{$p['id']}/decide", ['approve' => true])->assertForbidden();
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/announcements/{$p['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'published');

        // الانتهاء والأرشفة التلقائية
        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00:00', 'Asia/Damascus'));
        $this->artisan('announcements:sweep')->assertSuccessful();
        $this->assertSame('expired', Announcement::allCompanies()->find($a['id'])->status);
    }
}
