<?php

namespace Tests\Feature;

use App\Models\DevelopmentPlan;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\PointLog;
use App\Services\Work\Points;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** دفعة الملاحظات 15g: المهام، مؤشرات الأداء، النقاط من الأداء، وخطط تطوير الإدارة والشركة */
class WorkTest extends TestCase
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

    private function emp(string $email): Employee { return Employee::allCompanies()->where('email', $email)->firstOrFail(); }

    private function points(Employee $e, string $source): int { return (int) PointLog::allCompanies()->where('employee_id', $e->id)->where('source', $source)->sum('points'); }

    public function test_manager_assigns_task_employee_submits_and_points_are_added(): void
    {
        $samer = $this->emp('samer.halabi@uniqueness.sy'); $other = $this->emp('hani.samman@uniqueness.sy');
        $this->login('alaa.sheikhani@uniqueness.sy');
        $this->postJson('/api/work/tasks', ['title' => 'مهمة خارج الفريق', 'employee_id' => $other->id, 'priority' => 'normal'])->assertUnprocessable()->assertJsonPath('code', 'out_of_scope');
        $id = $this->postJson('/api/work/tasks', ['title' => 'تحديث خادم البريد', 'employee_id' => $samer->id, 'priority' => 'high', 'due_on' => '2026-10-20'])->assertCreated()->json('data.id');
        $this->getJson('/api/work/tasks?view=team')->assertOk()->assertJsonPath('data.0.id', $id);

        $this->login('samer.halabi@uniqueness.sy');
        $this->getJson('/api/work/tasks')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.can.progress', true);
        $this->postJson("/api/work/tasks/{$id}/progress", ['status' => 'in_progress', 'progress' => 40])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/work/tasks/{$id}/progress", ['status' => 'done', 'note' => 'تم التحديث واختباره'])->assertOk()->assertJsonPath('data.status', 'review');
        $this->postJson("/api/work/tasks/{$id}/decide", ['approve' => true])->assertForbidden();   // لا يعتمد مهمته بنفسه
        $this->assertSame(0, $this->points($samer, 'task'));

        $this->login('alaa.sheikhani@uniqueness.sy');
        $this->postJson("/api/work/tasks/{$id}/decide", ['approve' => false, 'note' => 'ق'])->assertUnprocessable();
        $this->postJson("/api/work/tasks/{$id}/decide", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.points', 10);
        $this->assertSame(10, $this->points($samer, 'task'));
        $this->postJson("/api/work/tasks/{$id}/cancel", ['note' => 'لا داعي لها الآن'])->assertStatus(409);

        // مهمة ذاتية متأخرة: تُنجز مباشرة بنصف النقاط
        $this->login('samer.halabi@uniqueness.sy');
        $self = $this->postJson('/api/work/tasks', ['title' => 'قراءة دليل الأمان', 'employee_id' => $samer->id, 'priority' => 'normal', 'due_on' => '2026-10-01'])->assertCreated()->json('data.id');
        $this->postJson("/api/work/tasks/{$self}/progress", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(10 + 3, $this->points($samer, 'task'));   // 5 × 0.5 = 2.5 ← 3
        $this->getJson('/api/me/rewards')->assertOk()->assertJsonPath('points', (int) $samer->points + 13);   // الرصيد في متجر المكافآت
        $this->postJson("/api/work/tasks/{$self}/comments", ['body' => 'ملاحظة'])->assertOk();
        $this->login('dana.shami@uniqueness.sy');
        $this->getJson("/api/work/tasks/{$id}")->assertNotFound();   // ليست له
    }

    public function test_kpis_scopes_entries_and_points(): void
    {
        $samer = $this->emp('samer.halabi@uniqueness.sy');
        $this->login('alaa.sheikhani@uniqueness.sy');
        $k = $this->postJson('/api/work/kpis', ['scope' => 'employee', 'employee_id' => $samer->id, 'name_ar' => 'التذاكر المغلقة', 'unit' => 'count', 'target' => 100, 'direction' => 'higher', 'period' => 'month'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/work/kpis', ['scope' => 'company', 'name_ar' => 'رضا العملاء', 'unit' => 'percent', 'target' => 90, 'direction' => 'higher', 'period' => 'quarter'])->assertForbidden();
        $dept = $samer->department_id;
        $this->postJson('/api/work/kpis', ['scope' => 'department', 'department_id' => $dept, 'name_ar' => 'زمن الاستجابة', 'unit' => 'hours', 'target' => 4, 'direction' => 'lower', 'period' => 'month'])->assertCreated();
        $this->postJson("/api/work/kpis/{$k}/entries", ['period' => '2026-11', 'actual' => 50])->assertUnprocessable();   // فترة لم تبدأ
        $this->postJson("/api/work/kpis/{$k}/entries", ['period' => '2026-10', 'actual' => 115])->assertOk()->assertJsonPath('data.latest.achievement', 115);
        $this->assertSame(30, $this->points($samer, 'kpi'));
        $this->postJson("/api/work/kpis/{$k}/entries", ['period' => '2026-10', 'actual' => 120])->assertOk();
        $this->assertSame(30, $this->points($samer, 'kpi'));   // مرة واحدة للفترة

        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/work/kpis/{$k}/entries", ['period' => '2026-09', 'actual' => 90])->assertForbidden();
        $this->login('hr@uniqueness.sy');
        $this->postJson('/api/work/kpis', ['scope' => 'company', 'name_ar' => 'رضا العملاء', 'unit' => 'percent', 'target' => 90, 'direction' => 'higher', 'period' => 'quarter'])->assertCreated();
        $this->login('samer.halabi@uniqueness.sy');
        $scopes = collect($this->getJson('/api/work/kpis')->assertOk()->json('data'))->pluck('scope')->unique()->sort()->values()->all();
        $this->assertSame(['company', 'department', 'employee'], $scopes);
        $this->login('dana.shami@uniqueness.sy');
        $this->assertSame(['company'], collect($this->getJson('/api/work/kpis')->json('data'))->pluck('scope')->unique()->values()->all());
    }

    public function test_review_points_settings_and_department_company_plans(): void
    {
        $samer = $this->emp('samer.halabi@uniqueness.sy');
        $this->login('hr@uniqueness.sy');
        $this->putJson('/api/work/points', ['enabled' => true, 'task' => ['low' => 1, 'normal' => 4, 'high' => 8, 'urgent' => 12], 'late_factor' => 0.5,
            'kpi_met' => 15, 'kpi_exceed' => 25, 'exceed_pct' => 110, 'review' => ['high' => 60, 'mid' => 30, 'low' => 0]])->assertOk()->assertJsonPath('data.review.high', 60);
        $this->putJson('/api/work/points', ['enabled' => true])->assertUnprocessable();
        Tenant::run($samer->company_id, function () use ($samer) {
            $r = PerformanceReview::with('employee')->where('employee_id', $samer->id)->firstOrFail();
            $r->performance = 3;
            $this->assertSame(60, Points::review($r));
            $this->assertSame(0, Points::review($r));   // مرة واحدة
        });

        // خطة للشركة (الموارد البشرية) وخطة لإدارة (رئيسها)
        $plan = ['scope' => 'company', 'title' => 'خطة التحول الرقمي', 'gaps' => 'مهارات رقمية', 'goals' => [['text' => 'تدريب كل الموظفين على النظام']],
            'activities' => [['title' => 'ورشة تدريبية عامة', 'kind' => 'course', 'due_on' => '2026-12-01']]];
        $id = $this->postJson('/api/development/plans', $plan)->assertCreated()->assertJsonPath('data.scope', 'company')->assertJsonPath('data.employee', null)->json('data.id');
        $this->login('samer.halabi@uniqueness.sy');
        $this->assertNotContains($id, collect($this->getJson('/api/development')->json('plans'))->pluck('id')->all());   // مسودة
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/development/plans/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->login('samer.halabi@uniqueness.sy');
        $this->assertContains($id, collect($this->getJson('/api/development')->json('plans'))->pluck('id')->all());
        $this->postJson('/api/development/plans', ['scope' => 'department', 'department_id' => $samer->department_id] + $plan)->assertForbidden();
        $this->login('alaa.sheikhani@uniqueness.sy');
        $this->postJson('/api/development/plans', ['scope' => 'department', 'department_id' => $samer->department_id] + $plan)->assertCreated()->assertJsonPath('data.department.id', $samer->department_id);
        $this->postJson('/api/development/plans', $plan)->assertForbidden();   // خطة الشركة للموارد البشرية
        $this->assertSame(2, DevelopmentPlan::allCompanies()->whereIn('scope', ['company', 'department'])->count());
    }
}
