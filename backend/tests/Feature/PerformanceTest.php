<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PerformanceAward;
use App\Models\PerformanceCycle;
use App\Models\PerformanceForm;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\Payroll\MonthCalc;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** M13: دورة 360 بنماذج حقيقية، النتيجة والمستوى، المعايرة، الاعتماد، مكافأة التقييم (D-28) إلى الكشف، الإعدادات، والتحليلات */
class PerformanceTest extends TestCase
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

    private function settings(array $over = []): array
    {
        $s = $this->getJson('/api/hr/performance/settings')->assertOk()->json();
        return ['config' => array_replace($s['config'], $over), 'competencies' => $s['competencies']];
    }

    public function test_seeded_cycle_matches_the_demo_and_approval_creates_awards_into_the_sheet(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/performance')->assertOk()->json();
        $this->assertSame('open', $d['cycle']['status']);
        $this->assertCount(32, $d['reviews']);
        $r = collect($d['reviews'])->firstWhere('employee.code', 'UHR-1007');
        $this->assertEquals(3.5, $r['score']);
        $this->assertSame([3, 2], [$r['performance'], $r['potential']]);   // usePerf('e1007') في الديمو

        // لا دورة ثانية فوق دورة مفتوحة لنفس الموظفين
        $c = $this->postJson('/api/hr/performance/cycles', ['name_ar' => 'دورة الربع الرابع', 'name_en' => 'Q4 cycle', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31'])->assertCreated()->json('data');
        $this->postJson("/api/hr/performance/cycles/{$c['id']}/open")->assertConflict()->assertJsonPath('code', 'cycle_open');

        // جدول المكافآت (D-28): 3.0–3.9 = 10٪ من الراتب، 4.0–5 = 300 نقطة؛ وما دون 3 خارج السلّم ← مراجعة
        $this->putJson('/api/hr/performance/settings', $this->settings(['rewards' => [['from' => 4, 'to' => 5, 'kind' => 'points', 'value' => 300], ['from' => 3, 'to' => 3.9, 'kind' => 'percent', 'value' => 10]]]))->assertOk();
        $res = $this->postJson("/api/hr/performance/cycles/{$d['cycle']['id']}/approve")->assertOk()->json('result');
        $this->assertSame(32, $res['approved']);
        $this->assertSame('closed', PerformanceCycle::allCompanies()->find($d['cycle']['id'])->status);
        $samer = $this->emp('UHR-1007');
        $a = PerformanceAward::allCompanies()->where('employee_id', $samer->id)->firstOrFail();
        $this->assertSame('calculated', $a->status);
        $this->assertEquals(round($samer->salary * 0.1, 2), $a->amount);

        // اعتماد صاحب الصلاحية ← يدخل كشف الشهر ويرفع الصافي
        $before = MonthCalc::compute($samer, '2026-10')['money']['net'];
        $this->postJson("/api/hr/performance/awards/{$a->id}", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.month', '2026-10');
        $after = MonthCalc::compute($samer->fresh(), '2026-10')['money'];
        $this->assertEquals($a->amount, $after['bonus']);
        $this->assertEqualsWithDelta($before + $a->amount, $after['net'], 0.01);
        $this->postJson("/api/hr/performance/awards/{$a->id}", ['approve' => true])->assertConflict()->assertJsonPath('code', 'decided');

        // خارج السلّم: القيمة يدوياً مع سبب، والنقاط تُضاف فوراً (مصروفة)
        $low = PerformanceAward::allCompanies()->where('status', 'review')->first();
        if ($low) {
            $this->postJson("/api/hr/performance/awards/{$low->id}", ['approve' => true, 'kind' => 'points', 'value' => 50])->assertUnprocessable();
            $pts = $low->employee->points;
            $this->postJson("/api/hr/performance/awards/{$low->id}", ['approve' => true, 'kind' => 'points', 'value' => 50, 'note' => 'تحسّن ملحوظ في الربع'])->assertOk()->assertJsonPath('data.status', 'paid');
            $this->assertSame($pts + 50, $low->employee->fresh()->points);
        }

        // الموظف يرى نتيجته ومكافأته (وزالت نماذج الدورة المغلقة)
        $this->login('samer.halabi@alfarada.sy');
        $me = $this->getJson('/api/me/reviews')->assertOk()->json();
        $this->assertEquals(3.5, $me['results'][0]['score']);
        $this->assertSame([], $me['forms']);
        $this->assertSame('approved', $me['results'][0]['award']['status']);
    }

    public function test_seeded_cycle_leaves_a_form_to_try_without_changing_scores(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $me = $this->getJson('/api/me/reviews')->assertOk()->json();
        $pending = collect($me['forms'])->where('status', 'pending')->values();
        $this->assertCount(1, $pending);
        $this->assertSame('peer', $pending[0]['role']);
        $this->assertCount(1, $me['cycles']['open']);
        $all = collect($me['competencies'])->mapWithKeys(fn ($x) => [$x['id'] => 5])->all();
        $this->postJson("/api/me/reviews/{$pending[0]['id']}", ['ratings' => $all, 'comment' => 'زميل متعاون', 'submit' => true])->assertOk()
            ->assertJsonPath('forms.0.status', 'submitted');
    }

    public function test_new_cycle_forms_score_calibration_and_peers(): void
    {
        $this->login('hr@alfarada.sy');
        $seeded = PerformanceCycle::allCompanies()->where('status', 'open')->firstOrFail();
        $this->postJson("/api/hr/performance/cycles/{$seeded->id}/approve")->assertOk();
        $samer = $this->emp('UHR-1007');
        $c = $this->postJson('/api/hr/performance/cycles', ['name_ar' => 'تقييم المالية', 'name_en' => 'Finance review', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-31', 'department_id' => $samer->department_id])->assertCreated()->json('data');
        $this->postJson("/api/hr/performance/cycles/{$c['id']}/open")->assertOk()->assertJsonPath('data.status', 'open');
        $this->putJson("/api/hr/performance/cycles/{$c['id']}", ['name_ar' => 'x', 'name_en' => 'x', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-31'])->assertConflict();

        $r = PerformanceReview::allCompanies()->where('performance_cycle_id', $c['id'])->where('employee_id', $samer->id)->firstOrFail();
        $forms = PerformanceForm::allCompanies()->where('performance_review_id', $r->id)->orderBy('id')->get();
        $this->assertSame(['self', 'manager'], $forms->pluck('role')->take(2)->all());
        $this->assertTrue($forms->where('role', 'peer')->isNotEmpty());
        $comps = $this->getJson('/api/hr/performance')->json('competencies');
        $all = fn (int $v) => collect($comps)->mapWithKeys(fn ($x) => [$x['id'] => $v])->all();

        // نموذج غيري ممنوع، والإرسال يحتاج كل الكفاءات
        $self = $forms->firstWhere('role', 'self');
        $this->postJson("/api/me/reviews/{$self->id}", ['ratings' => $all(4), 'submit' => true])->assertForbidden();
        $this->login('samer.halabi@alfarada.sy');
        $this->assertNotEmpty(collect($this->getJson('/api/me/reviews')->json('forms'))->where('role', 'self'));
        $this->postJson("/api/me/reviews/{$self->id}", ['ratings' => [$comps[0]['id'] => 4], 'submit' => true])->assertUnprocessable();
        $this->postJson("/api/me/reviews/{$self->id}", ['ratings' => $all(4), 'submit' => true])->assertOk();
        $this->postJson("/api/me/reviews/{$self->id}", ['ratings' => $all(5), 'submit' => true])->assertConflict()->assertJsonPath('code', 'submitted');

        // المدير: الدرجات + الإمكانات إلزامية
        $mgr = $forms->firstWhere('role', 'manager');
        $this->login(User::where('employee_id', $mgr->rater_employee_id)->value('email'));
        $this->postJson("/api/me/reviews/{$mgr->id}", ['ratings' => $all(5), 'submit' => true])->assertUnprocessable()->assertJsonValidationErrors('potential');
        $this->postJson("/api/me/reviews/{$mgr->id}", ['ratings' => $all(5), 'potential' => 3, 'comment' => 'ممتاز', 'submit' => true])->assertOk();
        $r->refresh();
        $this->assertEquals(round((5 * 50 + 4 * 20) / 70, 1), $r->score);   // الزملاء لم يرسلوا بعد ← الأوزان المتوفرة فقط
        $this->assertSame([3, 3], [$r->performance, $r->potential]);

        // المعايرة تبقى بعد إعادة الاحتساب
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/performance/reviews/{$r->id}/place", ['performance' => 2, 'potential' => 3])->assertOk()->assertJsonPath('data.calibrated', true);
        \App\Services\Performance\Reviews::recompute(Tenant::run($samer->company_id, fn () => $r->fresh()));
        $this->assertSame(2, $r->fresh()->performance);

        // الزملاء: إضافة وإزالة نموذج لم يُرسل
        $other = Employee::allCompanies()->where('company_id', $samer->company_id)->where('department_id', '!=', $samer->department_id)->where('status', 'active')->firstOrFail();
        $d = $this->postJson("/api/hr/performance/reviews/{$r->id}/peers", ['employee_id' => $other->id])->assertOk()->json('data');
        $this->postJson("/api/hr/performance/reviews/{$r->id}/peers", ['employee_id' => $other->id])->assertUnprocessable();
        $f = collect($d['forms'])->firstWhere('rater.id', $other->id);
        $this->deleteJson("/api/hr/performance/reviews/{$r->id}/peers/{$f['id']}")->assertOk();
        $this->deleteJson("/api/hr/performance/reviews/{$r->id}/peers/{$self->id}")->assertConflict();
        $this->postJson("/api/hr/performance/cycles/{$c['id']}/remind")->assertOk();
        $this->getJson("/api/hr/performance/cycles/{$c['id']}/export")->assertOk();
    }

    public function test_settings_validation_preview_and_recompute(): void
    {
        $this->login('hr@alfarada.sy');
        $this->putJson('/api/hr/performance/settings', $this->settings(['weights' => ['manager' => 50, 'peers' => 30, 'self' => 30]]))->assertUnprocessable()->assertJsonValidationErrors('config.weights.manager');
        $this->putJson('/api/hr/performance/settings', $this->settings(['rewards' => [['from' => 3, 'to' => 4, 'kind' => 'points', 'value' => 10], ['from' => 3.5, 'to' => 5, 'kind' => 'points', 'value' => 20]]]))->assertUnprocessable();
        $this->putJson('/api/hr/performance/settings', $this->settings(['high_from' => 2, 'mid_from' => 3]))->assertUnprocessable();
        $s = $this->settings(['weights' => ['manager' => 100, 'peers' => 0, 'self' => 0]]);
        $rows = $this->postJson('/api/hr/performance/settings/preview', $s)->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $samer = $this->emp('UHR-1007');
        $this->putJson('/api/hr/performance/settings', $s)->assertOk()->assertJsonPath('config.weights.manager', 100);
        $r = PerformanceReview::allCompanies()->where('employee_id', $samer->id)->firstOrFail();
        $mgr = PerformanceForm::allCompanies()->where('performance_review_id', $r->id)->where('role', 'manager')->firstOrFail();
        $this->assertEquals(round(collect($mgr->ratings)->avg(), 1), $r->score);
        $s['competencies'] = array_map(fn ($c, $i) => $i < 4 ? array_merge($c, ['active' => false]) : $c, $s['competencies'], array_keys($s['competencies']));
        $this->putJson('/api/hr/performance/settings', $s)->assertUnprocessable()->assertJsonValidationErrors('competencies');

        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/performance')->assertForbidden();
        $this->putJson('/api/hr/performance/settings', $s)->assertForbidden();
    }

    public function test_analytics_reads_system_data(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/analytics')->assertOk()->json();
        $this->assertSame(Employee::allCompanies()->where('company_id', $this->emp('UHR-1007')->company_id)->whereNotIn('status', ['terminated'])->count(), $d['headcount']['total']);
        $this->assertSame($d['headcount']['total'], array_sum(array_column($d['tenure'], 'n')));
        $this->assertCount(10, $d['heat']['days']);
        $this->assertNotEmpty($d['heat']['rows']);
        $this->assertSame('new', $d['funnel'][0]['stage']);
        $this->assertGreaterThanOrEqual($d['funnel'][1]['n'], $d['funnel'][0]['n']);
        $this->assertNotNull($d['pay']);
        $this->assertNotEmpty($d['performance']['grid']);
        $this->getJson('/api/hr/analytics/export')->assertOk();
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/analytics')->assertForbidden();
    }
}
