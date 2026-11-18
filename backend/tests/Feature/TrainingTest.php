<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Employee;
use App\Models\EmployeeCompetency;
use App\Models\Resignation;
use App\Models\TdCertificate;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdPlan;
use App\Models\TdQualification;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Services\Training\Records;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 23: التأهيل والتدريب والتطوير — الاحتياجات والبوابات، الخطة واعتمادها، التنفيذ والتعهد والنتيجة والشهادة، الأثر، الاستقالة، التأهيل */
class TrainingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $samer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        $this->samer = Employee::where('email', 'samer.halabi@uniqueness.sy')->first();
        $this->samer->update(['status' => 'active', 'salary' => 1000, 'currency' => 'USD']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void { $this->postJson('/api/auth/logout'); $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk(); }
    private function sig(): string { $i = imagecreatetruecolor(240, 80); imageline($i, 10, 60, 230, 20, imagecolorallocate($i, 255, 255, 255)); ob_start(); imagepng($i); return 'data:image/png;base64,'.base64_encode(ob_get_clean()); }

    public function test_needs_gates_appraisal_gaps_and_plan_approval(): void
    {
        $this->login('hr@uniqueness.sy');
        $comp = Competency::allCompanies()->where('company_id', $this->samer->company_id)->orderBy('id')->first();
        // FR-TD-003: الكفاءات المطلوبة لوظيفة سامر، ومستواه الحالي أقل بمستويين
        $job = Tenant::run($this->samer->company_id, fn () => Codes::items('jobs')[0]['id']);
        $this->samer->update(['job_id' => $job]);
        $this->postJson('/api/td/job-competencies', ['job_id' => $job, 'items' => [['competency_id' => $comp->id, 'level' => 4]]])->assertOk();
        $this->postJson('/api/td/employee-competency', ['employee_id' => $this->samer->id, 'competency_id' => $comp->id, 'level' => 2])->assertOk();
        $m = $this->getJson('/api/td/matrix')->assertOk();
        $row = collect($m->json('rows'))->firstWhere('id', $this->samer->id);
        $this->assertSame(['required' => 4, 'current' => 2], $row['cells'][$comp->id]);

        // POL-04 + BR-08/09: «دافعية» ← ليس احتياجاً تدريبياً؛ دليل واحد لا يكفي
        $n1 = $this->postJson('/api/td/needs', ['employee_id' => $this->samer->id, 'competency_id' => $comp->id, 'title' => 'التفاوض', 'path' => 'training', 'cause' => 'motivation', 'evidence' => ['interview', 'appraisal']])->assertCreated()->json('data');
        $this->postJson("/api/td/needs/{$n1['id']}/decide", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'not_training');
        $n2 = $this->postJson('/api/td/needs', ['employee_id' => $this->samer->id, 'competency_id' => $comp->id, 'title' => 'التفاوض', 'path' => 'training', 'cause' => 'knowledge', 'evidence' => ['interview'],
            'factors' => ['impact' => 4, 'gap' => 3, 'affected' => 1, 'urgency' => 3]])->assertCreated()->json('data');
        $this->assertSame(4, $n2['required_level']); $this->assertSame(2, $n2['current_level']);
        $this->postJson("/api/td/needs/{$n2['id']}/decide", ['action' => 'approve'])->assertStatus(422)->assertJsonPath('code', 'evidence_required');
        $this->putJson("/api/td/needs/{$n2['id']}", ['title' => 'التفاوض', 'path' => 'training', 'cause' => 'knowledge', 'evidence' => ['interview', 'observation'], 'employee_id' => $this->samer->id, 'competency_id' => $comp->id])->assertOk();
        $this->postJson("/api/td/needs/{$n2['id']}/decide", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        // حملة TNA ونموذج إدارة معبأ مسبقاً بفجوة سامر
        $this->postJson('/api/td/campaigns', ['title' => 'حملة 2027', 'year' => 2027, 'closes_on' => '2026-11-30'])->assertOk();
        $c = $this->getJson('/api/td/campaigns')->json('data.0');
        $form = $this->getJson("/api/td/campaigns/{$c['id']}/form/{$this->samer->department_id}")->assertOk();
        $this->assertTrue(collect($form->json('prefill.gaps'))->contains(fn ($g) => $g['employee_id'] === $this->samer->id));
        $this->postJson("/api/td/campaigns/{$c['id']}/form/{$this->samer->department_id}", ['items' => [['title' => 'إكسل متقدم', 'path' => 'training', 'employees' => [$this->samer->id]]]])->assertOk()->assertJsonPath('count', 1);

        // الخطة: بند من الاحتياج، فحص الموازنة، الاعتماد (الموارد البشرية ← المالية ← الرئيس التنفيذي) وقفل النسخة الأساسية
        $pid = $this->postJson('/api/td/plans', ['year' => 2027, 'title' => 'خطة 2027', 'budget' => 1000, 'currency' => 'USD'])->assertCreated()->json('id');
        $plan = $this->postJson("/api/td/plans/{$pid}/items", ['title' => 'التفاوض الفعال', 'path' => 'training', 'delivery' => 'external', 'month' => 3, 'trainees' => 5, 'estimated_cost' => 1500, 'need_ids' => [$n2['id']]])->assertOk();
        $this->assertSame('over_budget', $plan->json('data.checks.0.key'));
        $this->assertSame('planned', TdNeed::allCompanies()->find($n2['id'])->status);
        $this->postJson("/api/td/plans/{$pid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_hr');
        $this->postJson("/api/td/plans/{$pid}/decide", ['approve' => true])->assertForbidden();   // مقدّم الطلب لا يعتمد
        $this->login('nour.hassan@uniqueness.sy');
        $this->postJson("/api/td/plans/{$pid}/decide", ['approve' => true])->assertOk();
        $this->login('basel.atassi@uniqueness.sy');
        $this->postJson("/api/me/approvals/".collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'td_plan')['step_id'], ['decision' => 'approve', 'note' => 'الموازنة ضمن السقف المالي'])->assertOk();
        $this->login('hani.samman@uniqueness.sy');
        $this->postJson("/api/me/approvals/".collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'td_plan')['step_id'], ['decision' => 'approve'])->assertOk();
        $p = TdPlan::allCompanies()->find($pid);
        $this->assertSame('approved', $p->status); $this->assertNotNull($p->baseline);
        $this->assertSame('الموازنة ضمن السقف المالي', $p->finance_note);
        // BR-18: التعديل بعد الاعتماد بطلب تغيير
        $this->login('hr@uniqueness.sy');
        $item = $p->items()->first();
        $this->putJson("/api/td/plan-items/{$item->id}", ['title' => 'التفاوض الفعال', 'path' => 'training', 'delivery' => 'external', 'month' => 4, 'estimated_cost' => 900])->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $this->putJson("/api/td/plan-items/{$item->id}", ['title' => 'التفاوض الفعال', 'path' => 'training', 'delivery' => 'external', 'month' => 4, 'estimated_cost' => 900, 'change_reason' => 'تأجيل لتعارض موسم الذروة'])->assertOk();
        $this->assertSame(-600.0, (float) $item->fresh()->changes[0]['cost_delta']);

        // 10.1: اعتماد تقييم أداء ← احتياجات مقترحة من فجوات الكفاءة
        $hala = Employee::where('id', '!=', $this->samer->id)->whereNotNull('manager_id')->first();
        $hala->update(['job_id' => $job]);
        $rev = \App\Models\PerformanceReview::allCompanies()->create(['company_id' => $hala->company_id, 'performance_cycle_id' => \App\Models\PerformanceCycle::allCompanies()->value('id'), 'employee_id' => $hala->id,
            'ratings' => [$comp->id => ['total' => 2.0]], 'score' => 2.0, 'status' => 'approved']);
        Tenant::run($hala->company_id, fn () => $this->assertSame(1, app(\App\Services\Training\Needs::class)->fromAppraisal($rev)));
        $this->assertSame('suggested', TdNeed::allCompanies()->where('employee_id', $hala->id)->value('status'));
    }

    public function test_session_commitment_attendance_result_certificate_impact_and_resignation(): void
    {
        $this->login('hr@uniqueness.sy');
        $comp = Competency::allCompanies()->where('company_id', $this->samer->company_id)->orderBy('id')->first();
        $prog = $this->postJson('/api/td/programs', ['code' => 'NEG-01', 'title' => 'التفاوض الفعال', 'path' => 'training', 'delivery' => 'external', 'audience' => 'staff', 'method' => 'workshop',
            'hours' => 12, 'competencies' => [['id' => $comp->id, 'level' => 4]], 'has_test' => true, 'mandatory' => false, 'active' => true])->assertOk()->json('data.0');
        $center = $this->postJson('/api/td/centers', ['name' => 'مركز المهارات'])->assertOk()->json('centers.0.id');
        $res = $this->postJson('/api/td/sessions', ['program_id' => $prog['id'], 'center_id' => $center, 'capacity' => 2, 'currency' => 'USD',
            'meetings' => [['date' => '2026-10-20', 'from' => '09:00', 'to' => '13:00'], ['date' => '2026-10-21', 'from' => '09:00', 'to' => '13:00'], ['date' => '2026-10-22', 'from' => '09:00', 'to' => '13:00']],
            'costs' => [['type' => 'center', 'amount' => 1200, 'currency' => 'USD']]])->assertCreated();
        $sid = $res->json('id');
        $this->assertContains('quotes', array_column($res->json('warnings'), 'key'));   // BR-05

        // BR-01: موظف في فترة التجربة يُرفض إلا باستثناء موثق؛ السعة ← قائمة انتظار
        $prob = Employee::where('id', '!=', $this->samer->id)->first(); $prob->update(['status' => 'probation']);
        $third = Employee::whereNotIn('id', [$this->samer->id, $prob->id])->where('status', 'active')->first();
        $r = $this->postJson("/api/td/sessions/{$sid}/nominate", ['employee_ids' => [$prob->id, $this->samer->id]])->assertOk();
        $this->assertSame(['probation'], $r->json('blocked.0.reasons'));
        $this->postJson("/api/td/sessions/{$sid}/nominate", ['employee_ids' => [$prob->id], 'exception' => 'برنامج إلزامي للتعامل مع العملاء'])->assertOk()->assertJsonPath('added', 1);
        $this->postJson("/api/td/sessions/{$sid}/nominate", ['employee_ids' => [$third->id]])->assertOk()->assertJsonPath('waitlist', 1);

        // BR-06: تكلفة الفرد 600 ≥ 30% من راتب 1000 ← تعهد F-03 قبل التأكيد، بمدة من الشرائح (300–1000 ← 6 أشهر)
        $en = TdEnrollment::allCompanies()->where('session_id', $sid)->where('employee_id', $this->samer->id)->first();
        $this->postJson("/api/td/enrollments/{$en->id}/confirm")->assertOk();
        $this->assertSame('pending_commitment', $en->fresh()->status);
        $c = TdCommitment::allCompanies()->where('enrollment_id', $en->id)->first();
        $this->assertSame([600.0, 6], [$c->cost, $c->months]);
        $this->login('samer.halabi@uniqueness.sy');
        $this->assertCount(1, $this->getJson('/api/me/training')->assertOk()->json('to_sign'));
        $this->postJson("/api/me/training/commitments/{$c->id}/sign", ['signature' => $this->sig()])->assertOk();
        $this->assertSame('confirmed', $en->fresh()->status);
        $this->assertSame('2027-04-22', $c->fresh()->ends_on->toDateString());
        $this->assertSame('training', \App\Models\AttendanceDay::allCompanies()->where('employee_id', $this->samer->id)->whereDate('date', '2026-10-20')->value('status'));

        // الحضور والنتيجة (BR-02 / AC-03): الآخر حضر جلسة واحدة ← غير ناجح بسبب الحضور
        $this->login('hr@uniqueness.sy');
        $other = TdEnrollment::allCompanies()->where('session_id', $sid)->where('employee_id', $prob->id)->first();
        $this->postJson("/api/td/enrollments/{$other->id}/confirm")->assertOk();
        if ($other->fresh()->status === 'pending_commitment') $this->postJson('/api/td/commitments/'.TdCommitment::allCompanies()->where('enrollment_id', $other->id)->value('id').'/sign', ['file_id' => \App\Models\StoredFile::allCompanies()->value('id')])->assertOk();
        foreach ([0, 1, 2] as $k) $this->postJson("/api/td/sessions/{$sid}/attendance", ['meeting' => $k, 'marks' => [$en->id => 'present', $other->id => $k === 0 ? 'present' : 'absent']])->assertOk();
        $this->postJson("/api/td/sessions/{$sid}/scores", ['rows' => [['id' => $en->id, 'pre' => 50, 'post' => 92], ['id' => $other->id, 'pre' => 40, 'post' => 80]]])->assertOk();
        $this->postJson("/api/td/sessions/{$sid}/complete")->assertOk();
        $this->assertSame(['passed', null], [$en->fresh()->result, $en->fresh()->result_reason]);
        $this->assertSame(['failed', 'attendance'], [$other->fresh()->result, $other->fresh()->result_reason]);
        $cert = TdCertificate::allCompanies()->where('employee_id', $this->samer->id)->first();
        $this->assertSame('excellence', $cert->type);   // ≥ 90
        $this->assertFalse(TdCertificate::allCompanies()->where('employee_id', $prob->id)->exists());
        $this->assertSame(3, TdImpact::allCompanies()->where('enrollment_id', $en->id)->count());
        $this->getJson("/api/public/td-verify/{$cert->code}")->assertOk()->assertJsonPath('data.status', 'valid');
        // BR-07: قرار غير الناجح
        $this->postJson("/api/td/enrollments/{$other->id}/failure", ['decision' => 'retrain', 'note' => 'إعادة في الدورة القادمة'])->assertOk();

        // BR-16: لا إغلاق قبل الاستبيان؛ الاستبيان العام مجهول الهوية
        $this->postJson("/api/td/sessions/{$sid}/close")->assertStatus(409)->assertJsonPath('code', 'close_blocked');
        $token = \App\Models\TdSession::allCompanies()->find($sid)->survey_token;
        $this->postJson('/api/auth/logout');
        $this->getJson("/api/public/td-survey/{$token}")->assertOk()->assertJsonCount(7, 'items');
        foreach ([5, 4] as $x) $this->postJson("/api/public/td-survey/{$token}", ['ratings' => array_fill(0, 7, $x), 'anonymous' => true])->assertCreated();
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/td/sessions/{$sid}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        // BR-03: نتائج المتدربين دون العتبة؟ متوسط 86 ≥ 70 ← لا حظر؛ تقييم ضعيف ← توصية حظر
        $this->postJson("/api/td/sessions/{$sid}/rate", ['scores' => ['content' => 2, 'trainer' => 2, 'punctuality' => 2, 'facilities' => 2, 'results' => 2, 'value' => 2]])->assertOk()->assertJsonPath('data.rating.recommendation', 'ban');
        $this->assertTrue(\App\Models\Notice::where('link', 'training:providers')->exists());

        // F-12 ← مهام؛ F-05 عند 60 يوماً بنجاح ← تحديث مستوى الكفاءة
        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/me/training/enrollments/{$en->id}/apply", ['items' => [['what' => 'تطبيق أسلوب الأسئلة المفتوحة', 'when' => '2026-11-15']]])->assertOk();
        $this->assertTrue(\App\Models\WorkTask::allCompanies()->where('employee_id', $this->samer->id)->where('title', 'like', 'تطبيق:%')->exists());
        $imp = TdImpact::allCompanies()->where('enrollment_id', $en->id)->where('kind', 'behavior')->first();
        $mgr = User::where('employee_id', $this->samer->manager_id)->first();
        $this->actingAs($mgr);
        $this->postJson("/api/me/training/impacts/{$imp->id}", ['ratings' => array_fill(0, 8, 80)])->assertStatus(422);
        $this->postJson("/api/me/training/impacts/{$imp->id}", ['ratings' => array_fill(0, 9, 80), 'barriers' => 'ضيق الوقت'])->assertOk();
        $this->assertSame(4, (int) EmployeeCompetency::allCompanies()->where('employee_id', $this->samer->id)->where('competency_id', $comp->id)->value('level'));

        // POL-11 / BR-13: الاستقالة خلال مدة التعهد ← مبلغ نسبي بانتظار القرار ← يُخصم في المخالصة بعد اعتماده
        Carbon::setTestNow(Carbon::parse('2026-12-22 10:00:00', 'Asia/Damascus'));   // بعد شهرين من 6
        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson('/api/me/exit', ['last_day' => '2027-02-20', 'reasons' => ['other'], 'text' => 'ظروف عائلية تمنع الاستمرار'])->assertOk();
        $c->refresh();
        $this->assertSame('breached', $c->status);
        $this->assertEqualsWithDelta(400, $c->recovery, 10);   // نحو ثلثي التكلفة
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/td/commitments/{$c->id}/decide", ['action' => 'recover'])->assertOk();
        $r = Resignation::allCompanies()->where('employee_id', $this->samer->id)->first();
        $this->assertEqualsWithDelta($c->fresh()->recovery, Tenant::run($r->company_id, fn () => \App\Services\Exit\ExitFlow::settlement($r))['training'], 0.01);
    }

    public function test_qualification_program_and_external_certificates(): void
    {
        $this->login('hr@uniqueness.sy');
        $new = Employee::where('id', '!=', $this->samer->id)->whereNotNull('manager_id')->first();
        $this->postJson('/api/td/qualifications', ['employee_id' => $new->id])->assertOk();
        $this->postJson('/api/td/qualifications', ['employee_id' => $new->id])->assertStatus(409);
        $q = TdQualification::allCompanies()->where('employee_id', $new->id)->first();
        $this->postJson("/api/td/qualifications/{$q->id}/evaluate", ['recommendation' => 'confirm'])->assertStatus(409)->assertJsonPath('code', 'stations_pending');
        $this->postJson("/api/td/qualifications/{$q->id}/sign", ['i' => 0, 'note' => 'أتم المحاور'])->assertOk();
        $this->postJson("/api/td/qualifications/{$q->id}/evaluate", ['recommendation' => 'confirm', 'note' => 'جاهز'])->assertOk();
        $q->refresh();
        $this->assertSame('done', $q->status);
        $this->assertSame('qualification', TdCertificate::allCompanies()->find($q->certificate_id)->type);

        // FR-TD-102: شهادة خارجية من الموظف ← بانتظار التحقق ← اعتماد
        $this->login('samer.halabi@uniqueness.sy');
        $f = $this->postJson('/api/me/training/files', ['file' => \Illuminate\Http\UploadedFile::fake()->create('pmp.pdf', 20, 'application/pdf')])->assertCreated()->json('id');
        $this->postJson('/api/me/training/certificates', ['title' => 'PMP', 'issuer' => 'PMI', 'issued_on' => '2026-01-10', 'valid_to' => '2026-11-30', 'file_id' => $f])->assertCreated();
        $cert = TdCertificate::allCompanies()->where('employee_id', $this->samer->id)->first();
        $this->assertSame('pending', $cert->status);
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/td/certificates/{$cert->id}/verify", ['ok' => true])->assertOk();
        // FR-TD-103: تنبيه قبل الانتهاء واحتياج تجديد آلي عند آخر نقطة
        Tenant::run($this->samer->company_id, fn () => app(\App\Services\Training\Sessions::class)->sweep());
        $this->assertEqualsCanonicalizing([60, 90], $cert->fresh()->alerts);   // بقي 49 يوماً ← تنبيه الستين
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'Asia/Damascus'));
        Tenant::run($this->samer->company_id, fn () => app(\App\Services\Training\Sessions::class)->sweep());
        $this->assertTrue(TdNeed::allCompanies()->where('source_ref', 'certificate:'.$cert->id)->exists());
        $rec = $this->getJson("/api/td/employees/{$this->samer->id}/record")->assertOk();
        $this->assertSame('PMP', $rec->json('data.certificates.0.title'));
    }
}
