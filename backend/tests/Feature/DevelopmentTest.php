<?php

namespace Tests\Feature;

use App\Models\DevelopmentActivity;
use App\Models\DevelopmentPlan;
use App\Models\Employee;
use App\Models\Notice;
use App\Models\SuccessionPlan;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** D-23 التعاقب و D-24 خطة التطوير الفردية: النطاقات، التفعيل، التقدم، التنبيهات، المراجعة، الترشيح والفجوات */
class DevelopmentTest extends TestCase
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
    private function mail(Employee $e): string { return User::where('employee_id', $e->id)->value('email'); }

    public function test_development_plan_scope_activation_progress_alerts_and_review(): void
    {
        $samer = $this->emp('UHR-1007');
        $mgr = Employee::allCompanies()->findOrFail($samer->manager_id);
        $outsider = Employee::allCompanies()->where('company_id', $samer->company_id)->where('manager_id', '!=', $mgr->id)->where('id', '!=', $mgr->id)->whereNotNull('manager_id')->firstOrFail();
        $this->login($this->mail($mgr));
        $base = ['title' => 'تطوير مهارات التحليل', 'gaps' => 'تحليل البيانات', 'goals' => [['text' => 'إتقان الجداول المحورية']]];
        $this->postJson('/api/development/plans', $base + ['employee_id' => $outsider->id])->assertUnprocessable()->assertJsonPath('code', 'out_of_scope');
        $p = $this->postJson('/api/development/plans', $base + ['employee_id' => $samer->id])->assertCreated()->json('data');
        $this->assertSame('draft', $p['status']);
        $this->postJson("/api/development/plans/{$p['id']}/activate")->assertUnprocessable()->assertJsonPath('code', 'no_activities');
        $this->putJson("/api/development/plans/{$p['id']}", $base + ['activities' => [
            ['title' => 'دورة Excel متقدم', 'kind' => 'course', 'owner_employee_id' => $samer->id, 'due_on' => '2026-10-20'],
            ['title' => 'مشروع تقرير شهري', 'kind' => 'project', 'owner_employee_id' => $mgr->id, 'due_on' => '2026-11-30']]])->assertOk()->assertJsonCount(2, 'data.activities');
        $this->postJson("/api/development/plans/{$p['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.next_review', '2027-01-12');

        // الموظف يحدّث نشاطه ولا يلغي، وغيره لا يحدّث
        $acts = DevelopmentActivity::allCompanies()->where('development_plan_id', $p['id'])->orderBy('id')->get();
        $this->login('samer.halabi@alfarada.sy');
        $mine = $this->getJson('/api/development')->assertOk()->json();
        $this->assertContains($p['id'], array_column($mine['plans'], 'id'));
        $this->assertFalse($mine['can']['team']);
        $this->postJson("/api/development/activities/{$acts[0]->id}", ['status' => 'in_progress', 'progress' => 50, 'note' => 'أنهيت نصف الدورة'])->assertOk();
        $this->postJson("/api/development/activities/{$acts[0]->id}", ['status' => 'cancelled'])->assertForbidden();
        $this->postJson("/api/development/plans/{$p['id']}/review", ['note' => 'مراجعة', 'rating' => 3])->assertForbidden();
        $this->login($this->mail($outsider));
        $this->postJson("/api/development/activities/{$acts[0]->id}", ['status' => 'done'])->assertForbidden();

        // تأخر النشاط ← تنبيه مرة واحدة
        Carbon::setTestNow(Carbon::parse('2026-10-25 09:00:00', 'Asia/Damascus'));
        $run = fn () => Tenant::run($samer->company_id, fn () => app(\App\Services\Development\DevelopmentPlans::class)->sweep());
        $this->assertGreaterThanOrEqual(1, $run()['overdue']);
        $this->assertSame(0, $run()['overdue']);
        $this->assertTrue(Notice::allCompanies()->where('user_id', User::where('employee_id', $samer->id)->value('id'))->where('text_ar', 'like', '%دورة Excel متقدم%')->exists());

        // المراجعة وإكمال الخطة
        $this->login($this->mail($mgr));
        $this->postJson("/api/development/plans/{$p['id']}/review", ['note' => 'تقدم جيد في الربع', 'rating' => 4, 'goals_done' => [0]])->assertOk()->assertJsonPath('data.goals.0.done', true)->assertJsonPath('data.next_review', '2027-01-25');
        $this->postJson("/api/development/plans/{$p['id']}/review", ['note' => 'اكتملت الأهداف', 'rating' => 5, 'complete' => true])->assertOk()->assertJsonPath('data.status', 'done');
        $this->putJson("/api/development/plans/{$p['id']}", $base)->assertConflict()->assertJsonPath('code', 'plan_done');
    }

    public function test_succession_nomination_gap_and_linked_plan(): void
    {
        $samer = $this->emp('UHR-1007');
        $mgr = Employee::allCompanies()->findOrFail($samer->manager_id);
        $teammate = Employee::allCompanies()->where('manager_id', $mgr->id)->where('id', '!=', $samer->id)->first();
        $outsider = Employee::allCompanies()->where('company_id', $samer->company_id)->where('manager_id', '!=', $mgr->id)->where('id', '!=', $mgr->id)->whereNotNull('manager_id')->firstOrFail();
        $comps = $this->getJson('/api/development')->json('competencies') ?? [];

        $this->login($this->mail($mgr));
        $this->postJson('/api/development/succession', ['title_ar' => 'مدير', 'title_en' => 'Manager', 'risk' => 'high', 'competencies' => [1]])->assertForbidden();

        $this->login('hr@alfarada.sy');
        $comps = $this->getJson('/api/development')->assertOk()->json('competencies');
        $sp = $this->postJson('/api/development/succession', ['title_ar' => 'رئيس قسم المحاسبة', 'title_en' => 'Head of accounting', 'department_id' => $samer->department_id, 'incumbent_id' => $mgr->id,
            'risk' => 'high', 'competencies' => array_column(array_slice($comps, 0, 2), 'id')])->assertCreated()->json('data');
        $this->postJson("/api/development/succession/{$sp['id']}/candidates", ['employee_id' => $samer->id, 'readiness' => '1y'])->assertConflict();
        $this->postJson("/api/development/succession/{$sp['id']}/activate")->assertOk()->assertJsonPath('data.gap', true);

        // المدير يرشّح من فريقه فقط؛ شاغل المنصب لا يُرشَّح
        $this->login($this->mail($mgr));
        $this->postJson("/api/development/succession/{$sp['id']}/candidates", ['employee_id' => $outsider->id, 'readiness' => 'now'])->assertUnprocessable()->assertJsonPath('code', 'out_of_scope');
        $d = $this->postJson("/api/development/succession/{$sp['id']}/candidates", ['employee_id' => $samer->id, 'readiness' => '3y', 'note' => 'واعد'])->assertOk()->json('data');
        $this->assertCount(1, $d['candidates']);
        $this->assertTrue($d['gap']);

        // فجوة ← تنبيه مرة واحدة
        $run = fn () => Tenant::run($samer->company_id, fn () => app(\App\Services\Development\Succession::class)->sweep());
        $this->assertGreaterThanOrEqual(1, $run()['gaps']);
        $this->assertSame(0, $run()['gaps']);

        // HR: الفجوات + خطة تطوير مرتبطة؛ ثم مرشح «جاهز الآن» يسد الفجوة
        $this->login('hr@alfarada.sy');
        $cid = $d['candidates'][0]['id'];
        $this->putJson("/api/development/candidates/{$cid}", ['readiness' => '1y', 'create_plan' => true])->assertUnprocessable()->assertJsonValidationErrors('gaps');
        $r = $this->putJson("/api/development/candidates/{$cid}", ['readiness' => '1y', 'gaps' => 'إدارة الفريق والموازنات', 'create_plan' => true])->assertOk()->json('data');
        $plan = DevelopmentPlan::allCompanies()->findOrFail($r['candidates'][0]['plan']['id']);
        $this->assertSame(['draft', 'succession', $samer->id], [$plan->status, $plan->source, $plan->employee_id]);
        $this->postJson("/api/development/succession/{$sp['id']}/candidates", ['employee_id' => $mgr->id, 'readiness' => 'now'])->assertUnprocessable();
        if ($teammate) $this->postJson("/api/development/succession/{$sp['id']}/candidates", ['employee_id' => $teammate->id, 'readiness' => 'now'])->assertOk()->assertJsonPath('data.gap', false);
        $this->postJson("/api/development/succession/{$sp['id']}/review", ['note' => 'مراجعة نصف سنوية للمرشحين'])->assertOk()->assertJsonPath('data.status', 'reviewed');

        // الموظف لا يرى التعاقب
        $this->login('samer.halabi@alfarada.sy');
        $this->assertSame([], $this->getJson('/api/development')->json('succession'));
    }

    public function test_settings_and_seeded_sample(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/development')->assertOk()->json();
        $this->assertSame(3, $d['settings']['review_months']);
        $this->assertTrue(collect($d['succession'])->contains('gap', true));
        $this->assertTrue(collect($d['plans'])->flatMap(fn ($p) => $p['activities'])->contains('overdue', true));
        $s = $d['settings'];
        $s['readiness'][0]['ar'] = 'جاهز فوراً';
        $s['review_months'] = 6;
        $this->putJson('/api/development/settings', $s)->assertOk()->assertJsonPath('settings.readiness.0.ar', 'جاهز فوراً')->assertJsonPath('settings.review_months', 6);
        $s['readiness'][1]['key'] = 'now';
        $this->putJson('/api/development/settings', $s)->assertUnprocessable();
        $this->login('samer.halabi@alfarada.sy');
        $this->putJson('/api/development/settings', $s)->assertForbidden();
        $this->assertSame(1, SuccessionPlan::allCompanies()->count());
    }
}
