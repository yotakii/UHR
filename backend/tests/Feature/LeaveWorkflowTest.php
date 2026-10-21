<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notice;
use App\Models\OvertimeRequest;
use App\Models\RequestStep;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** M09 + المسار الموحد (SRS §10.1، §10.6): الإجازات بقواعد الشركة، التبرير، التكليف بالإضافي، النائب، التذكير */
class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));   // الإثنين
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function emp(string $code): Employee { return Employee::allCompanies()->where('code', $code)->firstOrFail(); }

    private function annual(array $over = []): array
    {
        return array_merge(['type' => 'annual', 'from' => '2026-10-26', 'to' => '2026-10-27', 'reason' => 'سفر عائلي', 'substitute_id' => $this->emp('UHR-1009')->id], $over);
    }

    public function test_notice_substitute_and_eligibility_rules(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $d = $this->getJson('/api/me/leave')->assertOk()->json();
        $this->assertEquals(21, $d['balance']['entitlement']);
        $this->assertSame('annual', $d['types'][0]['key']);

        // يومان قبل 3 أيام فقط ← المهلة 3 أيام لـ 2–4 أيام: 14/15 تشرين الأول (بعد يومين) مرفوض
        $this->postJson('/api/me/leave', $this->annual(['from' => '2026-10-14', 'to' => '2026-10-15']))->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->postJson('/api/me/leave', $this->annual(['substitute_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('substitute_id');
        $this->postJson('/api/me/leave', $this->annual(['substitute_id' => $this->emp('UHR-1007')->id]))->assertUnprocessable()->assertJsonValidationErrors('substitute_id');
        $this->postJson('/api/me/leave', $this->annual(['type' => 'marriage', 'to' => '2026-11-10']))->assertUnprocessable()->assertJsonValidationErrors('to');           // حد 7 أيام
        $this->postJson('/api/me/leave', $this->annual(['type' => 'hourly', 'hours' => 6, 'from' => '2026-10-14']))->assertUnprocessable()->assertJsonValidationErrors('hours');
        // الطارئة معفاة من المهلة، والفحص المسبق يعرض الأيام والمستويات
        $c = $this->postJson('/api/me/leave/check', $this->annual(['type' => 'emergency', 'from' => '2026-10-12', 'to' => '2026-10-12']))->assertOk()->json();
        $this->assertSame([], $c['errors']);
        $this->assertEquals(1, $c['days']);
        $this->assertSame(['المدير المباشر', 'الموارد البشرية'], array_column($c['levels'], 'ar'));
    }

    public function test_manager_then_hr_approve_and_the_balance_moves(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/leave', $this->annual())->assertCreated()->json('data');
        $this->assertSame(['pending_manager', 2.0], [$l['status'], (float) $l['days']]);
        $this->assertSame(['pending', 'waiting'], array_column($l['steps'], 'status'));
        $this->assertTrue(Notice::where('user_id', User::where('email', 'alaa.sheikhani@alfarada.sy')->value('id'))->where('link', 'approvals')->exists());

        // HR لا يقرر قبل المدير، والمدير يرى الطلب في صندوقه
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'approve'])->assertForbidden()->assertJsonPath('code', 'not_your_turn');
        $this->login('alaa.sheikhani@alfarada.sy');
        $box = $this->getJson('/api/me/approvals')->assertOk()->json('data');
        $this->assertContains($l['id'], array_column($box, 'id'));
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'reject', 'note' => 'قصير'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->assertSame('pending_hr', LeaveRequest::find($l['id'])->status);

        // الموظف لا يلغي بعد قرار المدير
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson("/api/me/leave/{$l['id']}/cancel")->assertStatus(409)->assertJsonPath('code', 'cancel_locked');

        $this->login('hr@alfarada.sy');
        $hrStep = RequestStep::where('subject_id', $l['id'])->where('status', 'pending')->value('id');
        $this->assertSame(1, $this->getJson('/api/hr/leaves')->json('counts.pending') >= 1 ? 1 : 0);
        $this->postJson("/api/me/approvals/{$hrStep}", ['decision' => 'approve', 'note' => 'تم'])->assertOk();
        $x = LeaveRequest::find($l['id']);
        $this->assertSame('approved', $x->status);
        $this->assertEquals(3, Employee::find($x->employee_id)->leave_used);                 // 1 + يومان
        $this->assertTrue(AuditLog::where('action', 'request.approved')->where('subject_id', $x->id)->exists());
        $this->assertTrue(Notice::where('user_id', User::where('email', 'samer.halabi@alfarada.sy')->value('id'))->where('text_ar', 'like', 'تمت الموافقة النهائية%')->exists());
        // الإجازة المعتمدة تظهر في الدوام
        Tenant::set($x->employee->company);
        $this->assertSame('leave', collect(\App\Services\Attendance\RecentDays::rows($x->employee, 14, '2026-10-27'))->firstWhere('date', '2026-10-26')['status']);
    }

    public function test_cancel_before_manager_and_rejection_reason(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/leave', $this->annual())->assertCreated()->json('data');
        $this->assertTrue($l['can_cancel']);
        $this->postJson("/api/me/leave/{$l['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $l2 = $this->postJson('/api/me/leave', $this->annual())->assertCreated()->json('data');            // يمكن تقديمه من جديد
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->postJson("/api/me/approvals/{$l2['step_id']}", ['decision' => 'reject', 'note' => 'ضغط عمل في نهاية الشهر'])->assertOk();
        $this->assertSame(['rejected', 'rejected', 'cancelled'], [LeaveRequest::find($l2['id'])->status, ...RequestStep::where('subject_id', $l2['id'])->orderBy('level')->pluck('status')->all()]);
    }

    public function test_over_balance_and_department_clash_need_an_approval_note(): void
    {
        Tenant::set($this->emp('UHR-1007')->company);
        $this->emp('UHR-1007')->update(['leave_used' => 20]);   // بقي يوم واحد
        $this->login('hr@alfarada.sy');
        // زميل في نفس الإدارة بإجازة معتمدة بنفس الفترة
        LeaveRequest::create(['employee_id' => $this->emp('UHR-1010')->id, 'type' => 'annual', 'from' => '2026-10-26', 'to' => '2026-10-26', 'reason' => 'x', 'status' => 'approved', 'days' => 1]);
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/leave', $this->annual())->assertCreated()->json('data');
        $this->assertArrayHasKey('over_balance', $l['warnings']);
        $this->assertSame('UHR-1010', $this->emp('UHR-1010')->code);
        $this->assertCount(1, $l['warnings']['conflict']);
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'approve'])->assertUnprocessable()->assertJsonPath('code', 'note_required');
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'approve', 'note' => 'أتحمّل مسؤولية التغطية'])->assertOk();
    }

    public function test_long_leave_adds_the_executive_and_the_executive_goes_to_the_ceo(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/leave', $this->annual(['from' => '2026-11-02', 'to' => '2026-11-12']))->assertCreated()->json('data');   // 9 أيام عمل > 7
        $this->assertSame(['المدير المباشر', 'الموارد البشرية', 'المدير التنفيذي'], array_map(fn ($s) => $s['role']['ar'], $l['steps']));
        $this->login('mazen.droubi@alfarada.sy');
        $m = $this->postJson('/api/me/leave', $this->annual(['substitute_id' => $this->emp('UHR-1002')->manager_id ? $this->emp('UHR-1001')->id : $this->emp('UHR-1001')->id]))->assertCreated()->json('data');
        $this->assertSame(['الرئيس التنفيذي'], array_map(fn ($s) => $s['role']['ar'], $m['steps']));
        $this->assertSame('pending_exec', $m['status']);
    }

    public function test_manager_on_leave_hands_the_request_to_the_deputy(): void
    {
        $alaa = $this->emp('UHR-1008');
        Tenant::set($alaa->company);
        LeaveRequest::create(['employee_id' => $alaa->id, 'type' => 'annual', 'from' => '2026-10-11', 'to' => '2026-10-14', 'reason' => 'x', 'status' => 'approved', 'days' => 4]);
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->putJson('/api/me/delegate', ['employee_id' => $this->emp('UHR-1003')->id])->assertUnprocessable()->assertJsonPath('code', 'delegate_dept');   // من إدارة أخرى
        $this->putJson('/api/me/delegate', ['employee_id' => $this->emp('UHR-1011')->id])->assertOk()->assertJsonPath('delegate.id', $this->emp('UHR-1011')->id);
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/leave', $this->annual())->assertCreated()->json('data');
        $this->assertSame($this->emp('UHR-1011')->name, $l['steps'][0]['owner']['ar']);
        $this->assertSame($alaa->name, $l['steps'][0]['delegate_for']['ar']);
        $deputy = User::where('employee_id', $this->emp('UHR-1011')->id)->firstOrFail();
        $this->login($deputy->email);
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'approve'])->assertOk();
    }

    public function test_document_is_required_before_the_final_approval(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->postJson('/api/me/leave', ['type' => 'sick', 'from' => '2026-10-12', 'to' => '2026-10-13', 'reason' => 'رشح', 'substitute_id' => $this->emp('UHR-1009')->id])->assertCreated()->json('data');
        $this->assertTrue($l['doc_required']);
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->postJson("/api/me/approvals/{$l['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->login('hr@alfarada.sy');
        $step = RequestStep::where('subject_id', $l['id'])->where('status', 'pending')->value('id');
        $this->postJson("/api/me/approvals/{$step}", ['decision' => 'approve'])->assertStatus(409)->assertJsonPath('code', 'doc_required');
        $this->login('samer.halabi@alfarada.sy');
        $this->post("/api/me/leave/{$l['id']}/document", ['file' => UploadedFile::fake()->create('report.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/me/approvals/{$step}", ['decision' => 'approve'])->assertOk();
        $this->assertEquals(2, Employee::allCompanies()->where('code', 'UHR-1007')->value('sick_used'));
    }

    public function test_justification_window_and_approval_justifies_the_day(): void
    {
        $e = $this->emp('UHR-1007');
        Tenant::set($e->company);
        AttendanceDay::updateOrCreate(['employee_id' => $e->id, 'date' => '2026-10-11'], ['in_at' => Carbon::parse('2026-10-11 08:40:00', 'Asia/Damascus'), 'out_at' => Carbon::parse('2026-10-11 16:00:00', 'Asia/Damascus'), 'status' => 'late', 'justified' => false]);
        $this->login('samer.halabi@alfarada.sy');
        $issues = $this->getJson('/api/me/attendance')->assertOk()->json('issues');
        $day = collect($issues)->firstWhere('date', '2026-10-11');
        $this->assertSame(['late', true], [$day['status'], $day['open']]);
        $this->postJson('/api/me/justifications', ['date' => '2026-10-07', 'reason' => 'ازدحام شديد'])->assertUnprocessable();                 // خارج المهلة
        $j = $this->postJson('/api/me/justifications', ['date' => '2026-10-11', 'reason' => 'ازدحام شديد على طريق المزة'])->assertCreated()->json('data');
        $this->assertSame(['justify', 40], [$j['kind'], $j['minutes']]);
        $this->postJson('/api/me/justifications', ['date' => '2026-10-11', 'reason' => 'مرة ثانية'])->assertUnprocessable()->assertJsonPath('code', 'already_justified');
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->postJson("/api/me/approvals/{$j['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->login('hr@alfarada.sy');
        $this->assertSame(1, collect($this->getJson('/api/hr/leaves?tab=justify')->json('data'))->where('id', $j['id'])->count());
        $step = RequestStep::where('subject_id', $j['id'])->where('status', 'pending')->value('id');
        $this->postJson("/api/me/approvals/{$step}", ['decision' => 'approve'])->assertOk();
        $this->assertTrue(AttendanceDay::where('employee_id', $e->id)->whereDate('date', '2026-10-11')->value('justified'));
        $row = collect($this->getJson('/api/hr/attendance?date=2026-10-11')->json('rows'))->firstWhere('employee.id', $e->id);
        $this->assertSame('justified', $row['status']);
    }

    public function test_overtime_assignment_by_the_manager_and_hr_approval(): void
    {
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->postJson('/api/me/overtime', ['employee_id' => $this->emp('UHR-1003')->id, 'date' => '2026-10-13', 'minutes' => 60, 'reason' => 'تسليم مشروع'])->assertForbidden();   // ليس من فريقه
        $this->postJson('/api/me/overtime', ['employee_id' => $this->emp('UHR-1007')->id, 'date' => '2026-10-13', 'minutes' => 180, 'reason' => 'تسليم مشروع'])->assertUnprocessable()->assertJsonPath('code', 'overtime_max');
        $box = $this->postJson('/api/me/overtime', ['employee_id' => $this->emp('UHR-1007')->id, 'date' => '2026-10-13', 'minutes' => 90, 'reason' => 'تسليم مشروع'])->assertOk()->json();
        $this->assertSame('pending_hr', $box['assigned'][0]['status']);
        $this->login('hr@alfarada.sy');
        $row = collect($this->getJson('/api/hr/leaves?tab=overtime')->json('data'))->first();
        $this->assertTrue($row['can_act']);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->assertSame('approved', OvertimeRequest::first()->status);
    }

    public function test_overdue_steps_are_reminded_once_per_period_and_demo_requests_have_steps(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/me/leave', $this->annual())->assertCreated();
        Tenant::set($this->emp('UHR-1007')->company);
        Carbon::setTestNow(Carbon::parse('2026-10-13 11:00:00', 'Asia/Damascus'));
        $n = app(Workflow::class)->remind();
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame(0, app(Workflow::class)->remind());
        // طلبات الديمو المعلّقة تحولت لخطوات وتظهر في صندوق المدير
        $this->assertGreaterThan(0, RequestStep::where('subject_type', (new LeaveRequest)->getMorphClass())->where('status', 'pending')->count());
        $this->login('hr@alfarada.sy');
        $this->assertGreaterThan(0, $this->getJson('/api/hr/leaves')->assertOk()->json('counts.pending'));
    }

    public function test_leave_policies_are_versioned_and_validated(): void
    {
        $this->login('hr@alfarada.sy');
        $types = Settings::POLICY_DEFAULTS['leave_types'];
        $types[0]['max_days'] = 20;
        $this->postJson('/api/hr/policies', ['key' => 'leave_types', 'effective_from' => '2026-10-12', 'value' => $types])->assertOk();
        $bad = $types; $bad[1]['doc'] = 'maybe';
        $this->postJson('/api/hr/policies', ['key' => 'leave_types', 'effective_from' => '2026-10-12', 'value' => $bad])->assertUnprocessable();
        $rules = Settings::POLICY_DEFAULTS['leave_rules']; $rules['substitute_required'] = false; $rules['reject_min'] = 20;
        $this->postJson('/api/hr/policies', ['key' => 'leave_rules', 'effective_from' => '2026-10-12', 'value' => $rules])->assertOk();
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/me/leave', $this->annual(['substitute_id' => null]))->assertCreated();             // البديل لم يعد إلزامياً
        $this->login('hr@alfarada.sy');
        $ann = ['first_year' => 14, 'standard' => 21, 'senior' => 30, 'standard_years' => 5, 'senior_years' => 3, 'senior_age' => 50, 'prorate_first_year' => true];
        $this->postJson('/api/hr/policies', ['key' => 'annual_leave', 'effective_from' => '2026-10-12', 'value' => $ann])->assertUnprocessable()->assertJsonValidationErrors('senior_years');
    }
}
