<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\ExitNotice;
use App\Models\Notice;
use App\Models\Resignation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\ExitNoticeMail;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** M11 حسب HR.01-03: الاستقالة بالمسار (المدير ← HR بمقابلة ← المدير العام)، الرجوع، F-05، F-07 ← F-04، مقابلة الخروج، المخالصة، الإغلاق، الإنهاء F-03، البديل، D-17 */
class ExitTest extends TestCase
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

    private function emp(string $code): Employee { return Employee::allCompanies()->where('code', $code)->firstOrFail(); }

    private static function sig(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function resign(array $over = []): array
    {
        $this->login('samer.halabi@alfarada.sy');
        return $this->postJson('/api/me/exit', array_merge(['last_day' => '2026-11-12', 'reasons' => ['offer'], 'text' => 'أتقدم بطلب قبول استقالتي لفرصة عمل أخرى.', 'survey' => ['why' => 'فرصة أفضل']], $over))->assertOk()->json('data');
    }

    private function decide(string $email, string $decision = 'approve', ?string $note = null, ?int $id = null)
    {
        $this->login($email);
        $rows = collect($this->getJson('/api/me/approvals')->json('data'))->where('kind', 'exit');
        $row = $id ? $rows->firstWhere('id', $id) : $rows->sortByDesc('id')->first();
        $this->assertNotNull($row, "no exit row for {$email}");
        return $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => $decision, 'note' => $note]);
    }

    /** استقالة سامر معتمدة حتى clearance */
    private function approvedResignation(array $over = []): int
    {
        $r = $this->resign($over);
        $this->decide('alaa.sheikhani@alfarada.sy')->assertOk();
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/offboarding/{$r['id']}/interview", ['reasons' => ['offer'], 'notes' => 'تأكدت الأسباب في المقابلة', 'recommendation' => 'accept'])->assertOk();
        $this->decide('hr@alfarada.sy')->assertOk();
        $this->decide('hani.samman@alfarada.sy')->assertOk();
        $this->assertSame('clearance', Resignation::find($r['id'])->status);
        return $r['id'];
    }

    public function test_resignation_goes_manager_hr_interview_then_general_manager(): void
    {
        $r = $this->resign(['last_day' => '2026-11-12']);
        $this->assertSame('pending_manager', $r['status']);
        $this->assertTrue($r['notice_short']);   // 31 يوماً < 60
        $this->assertStringStartsWith('RSG-2026-', $r['ref_no']);
        $this->assertSame('resigning', $this->emp('UHR-1007')->status);
        $this->postJson('/api/me/exit', ['last_day' => '2026-12-30', 'reasons' => ['offer'], 'text' => 'طلب ثانٍ للاستقالة.'])->assertConflict()->assertJsonPath('code', 'exit_open');
        $this->postJson('/api/me/exit', ['last_day' => '2026-10-01', 'reasons' => ['nope'], 'text' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['last_day', 'reasons.0', 'text']);

        $this->decide('alaa.sheikhani@alfarada.sy')->assertOk();
        $this->assertSame('pending_hr', Resignation::find($r['id'])->status);
        $this->decide('hr@alfarada.sy')->assertConflict()->assertJsonPath('code', 'interview_required');
        $this->postJson("/api/hr/offboarding/{$r['id']}/interview", ['reasons' => ['offer', 'conditions'], 'notes' => 'المقابلة أكدت السبب', 'recommendation' => 'accept'])->assertOk();
        $this->decide('hr@alfarada.sy')->assertOk();
        $this->assertSame('pending_exec', Resignation::find($r['id'])->status);
        $this->decide('hani.samman@alfarada.sy')->assertOk();
        $x = Resignation::find($r['id']);
        $this->assertSame('clearance', $x->status);
        $this->assertTrue(Notice::allCompanies()->where('link', 'offboarding:'.$x->id)->where('audience', 'user')->exists());   // تقانة المعلومات (admin)

        // المدير لا يرى الاستبيان ولا مقابلة HR، والموظف لا يرى مقابلة HR
        $this->login('samer.halabi@alfarada.sy');
        $mine = $this->getJson('/api/me/exit')->json('data');
        $this->assertNull($mine['hr_interview']);
        $this->assertSame('فرصة أفضل', $mine['survey']['why']);
        $this->login('hr@alfarada.sy');
        $this->assertSame('accept', $this->getJson("/api/hr/offboarding/{$x->id}")->json('data.hr_interview.recommendation'));
    }

    public function test_rejection_and_withdrawal_restore_the_employee(): void
    {
        $r = $this->resign();
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson("/api/me/exit/{$r['id']}/withdraw", ['text' => 'تراجعت عن قراري بعد النقاش'])->assertOk();
        $this->assertSame('cancelled', Resignation::find($r['id'])->status);
        $this->assertSame('active', $this->emp('UHR-1007')->status);

        $r2 = $this->resign();
        $this->decide('alaa.sheikhani@alfarada.sy')->assertOk();
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson("/api/me/exit/{$r2['id']}/withdraw", ['text' => 'أرغب بالرجوع عن الاستقالة'])->assertOk()->assertJsonPath('data.withdrawal.status', 'pending');
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/offboarding/{$r2['id']}/withdrawal", ['accept' => true, 'note' => 'قصير'])->assertUnprocessable();
        $this->postJson("/api/hr/offboarding/{$r2['id']}/withdrawal", ['accept' => true, 'note' => 'تم الاتفاق مع المدير، دون تغيير في العقد'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('active', $this->emp('UHR-1007')->status);

        $r3 = $this->resign();
        $this->decide('alaa.sheikhani@alfarada.sy', 'reject', 'نحتاجه في المشروع الحالي')->assertOk();
        $this->assertSame('rejected', Resignation::find($r3['id'])->status);
        $this->assertSame('active', $this->emp('UHR-1007')->status);
    }

    public function test_handover_clearance_exit_interview_and_closing(): void
    {
        $id = $this->approvedResignation();
        $samer = $this->emp('UHR-1007'); $lama = $this->emp('UHR-1009');
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/offboarding/{$id}/close")->assertConflict()->assertJsonPath('code', 'before_last_day');

        // F-05: المسلِّم ← المستلم ← المدير ← HR
        $this->login('samer.halabi@alfarada.sy');
        $tasks = [['task' => 'صيانة نظام الحضور', 'status' => 'in_progress', 'type' => 'electronic', 'location' => 'git/uhr'], ['task' => 'توثيق الواجهات', 'status' => 'done']];
        $this->putJson("/api/me/exit/{$id}/handover", ['receiver_id' => $samer->id, 'tasks' => $tasks])->assertUnprocessable();
        $this->putJson("/api/me/exit/{$id}/handover", ['receiver_id' => $lama->id, 'tasks' => $tasks])->assertOk();
        $this->postJson("/api/me/exit/{$id}/handover/sign", ['as' => 'receiver'])->assertConflict()->assertJsonPath('code', 'out_of_order');
        $this->postJson("/api/me/exit/{$id}/handover/sign", ['as' => 'employee'])->assertOk();
        $this->postJson("/api/me/exit/{$id}/handover/sign", ['as' => 'receiver'])->assertForbidden();   // ليس المستلم
        $this->login('lama.azmeh@alfarada.sy');
        $this->assertCount(1, $this->getJson('/api/me/exit')->json('receiving'));
        $this->postJson("/api/me/exit/{$id}/handover/sign", ['as' => 'receiver'])->assertOk();
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->assertCount(1, $this->getJson('/api/me/exit')->json('team'));
        $this->postJson("/api/me/exit/{$id}/handover/sign", ['as' => 'manager'])->assertOk();
        $this->postJson("/api/me/exit/{$id}/clearance/manager/stamps", ['state' => 'none'])->assertOk();
        $this->postJson("/api/me/exit/{$id}/clearance/finance/loans", ['state' => 'none'])->assertForbidden();   // ليست جهته
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/offboarding/{$id}/clearance/manager/sign")->assertConflict()->assertJsonPath('code', 'section_incomplete');   // F-05 لم تُصادق من HR
        $this->postJson("/api/hr/offboarding/{$id}/handover/sign", ['as' => 'hr'])->assertOk()->assertJsonPath('data.handover.done', true);
        $this->login('alaa.sheikhani@alfarada.sy');
        $this->postJson("/api/me/exit/{$id}/clearance/manager/sign")->assertOk();

        // F-07: الأصول تكتمل باسترجاعها من سجل العهد
        $this->login('hr@alfarada.sy');
        $full = $this->getJson("/api/hr/offboarding/{$id}")->json('data');
        $assets = collect($full['clearance'])->firstWhere('key', 'admin')['items'][0];
        $this->assertSame('assets', $assets['auto']);
        $this->assertCount(2, $assets['pending']);
        foreach (['it' => ['devices', 'accounts', 'backup'], 'legal' => ['powers'], 'admin' => ['mobile', 'internet', 'vehicle', 'keys'], 'hr' => ['leave', 'health', 'id_card', 'grants']] as $sec => $items) {
            foreach ($items as $it) $this->postJson("/api/hr/offboarding/{$id}/clearance/{$sec}/{$it}", ['state' => 'none'])->assertOk();
        }
        $this->postJson("/api/hr/offboarding/{$id}/clearance/admin/assets", ['state' => 'done'])->assertConflict()->assertJsonPath('code', 'auto_item');
        $this->postJson("/api/hr/offboarding/{$id}/clearance/admin/sign")->assertConflict()->assertJsonPath('code', 'section_incomplete');
        foreach ($assets['pending'] as $a) $this->postJson("/api/hr/custody/{$a['id']}/return", ['condition' => 'good'])->assertOk();
        foreach (['it', 'legal', 'admin', 'hr'] as $sec) $this->postJson("/api/hr/offboarding/{$id}/clearance/{$sec}/sign")->assertOk();
        $this->login('basel.atassi@alfarada.sy');   // المالية
        $this->postJson("/api/hr/offboarding/{$id}/clearance/finance/work_advances", ['state' => 'none'])->assertOk();
        $this->postJson("/api/hr/offboarding/{$id}/clearance/finance/personal_advances", ['state' => 'none'])->assertOk();
        $this->postJson("/api/hr/offboarding/{$id}/clearance/finance/loans", ['state' => 'done', 'value' => 150])->assertOk();
        $this->postJson("/api/hr/offboarding/{$id}/clearance/finance/guarantee", ['state' => 'none'])->assertOk();
        $f = $this->postJson("/api/hr/offboarding/{$id}/clearance/finance/sign")->assertOk()->json('data');
        $this->assertMatchesRegularExpression('#^HR-\d+/2026$#', $f['clearance_no']);   // براءة الذمة F-04

        // الإغلاق: في آخر يوم (بعد الإعفاء من الإخطار) وبعد مقابلة الخروج
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/offboarding/{$id}/waive", ['note' => 'إعفاء بموافقة المدير', 'last_day' => '2026-10-12'])->assertOk();
        $this->postJson("/api/hr/offboarding/{$id}/close")->assertConflict()->assertJsonPath('code', 'exit_interview_required');
        $this->postJson("/api/hr/offboarding/{$id}/exit", ['answers' => ['why' => 'فرصة أفضل'], 'rating' => 4, 'would_return' => true, 'rehire' => false, 'noncompete' => true, 'rights' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'rehire_reason_required');
        $this->postJson("/api/hr/offboarding/{$id}/exit", ['answers' => ['why' => 'فرصة أفضل'], 'rating' => 4, 'would_return' => true, 'rehire' => true, 'noncompete' => true, 'rights' => true])->assertOk();
        $c = $this->postJson("/api/hr/offboarding/{$id}/close")->assertOk()->json('data');
        $this->assertSame('closed', $c['status']);
        $this->assertFalse($c['settlement']['notice_short']);   // أُعفي
        $this->assertEquals(150, $c['settlement']['finance']);
        $this->assertSame(12, $c['settlement']['days']);
        $this->assertSame('terminated', $samer->fresh()->status);
        $this->assertFalse((bool) User::where('employee_id', $samer->id)->value('active'));
        $this->assertSame('2026-10-27', $c['followups']['insurance']['due']);
        $this->postJson("/api/hr/offboarding/{$id}/certificate")->assertOk()->assertJsonPath('data.service_cert_no', 'HR/1/2026');
        $this->postJson('/api/auth/login', ['email' => 'samer.halabi@alfarada.sy', 'password' => '123456'])->assertForbidden();
    }

    public function test_notice_pay_when_the_notice_is_short_and_session_ends_on_close(): void
    {
        $id = $this->approvedResignation(['last_day' => '2026-10-20']);
        $r = Resignation::find($id);
        Tenant::set($r->employee->company);
        $s = \App\Services\Exit\ExitFlow::settlement($r);
        $this->assertTrue($s['notice_short']);
        $this->assertEquals(round((float) $r->employee->salary * 2, 2), $s['notice_pay']);
        Tenant::forget();

        // جلسة موظف موقوف تنتهي عند أول طلب
        $this->login('samer.halabi@alfarada.sy');
        User::where('employee_id', $r->employee_id)->update(['active' => false]);
        $this->app['auth']->forgetGuards();   // طلب جديد يقرأ المستخدم من القاعدة
        $this->getJson('/api/me/exit')->assertUnauthorized()->assertJsonPath('code', 'blocked');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user', null);
    }

    public function test_termination_by_hr_skips_hr_and_successor_moves_the_account(): void
    {
        $this->login('hr@alfarada.sy');
        $alaa = $this->emp('UHR-1008');
        $this->postJson('/api/hr/offboarding', ['employee_id' => $alaa->id, 'cause' => 'dismissal', 'last_day' => '2026-10-31', 'rehire' => true, 'notes' => 'ضعف الأداء المتكرر رغم الإنذارات'])
            ->assertUnprocessable()->assertJsonPath('code', 'reasons_required');
        $this->postJson('/api/hr/offboarding', ['employee_id' => $alaa->id, 'cause' => 'dismissal', 'reasons' => ['performance'], 'last_day' => '2026-10-31', 'rehire' => false, 'notes' => 'ضعف الأداء المتكرر رغم الإنذارات'])
            ->assertUnprocessable()->assertJsonPath('code', 'rehire_reason_required');
        $t = $this->postJson('/api/hr/offboarding', ['employee_id' => $alaa->id, 'cause' => 'dismissal', 'reasons' => ['performance', 'attendance'], 'last_day' => '2026-10-31', 'rehire' => false,
            'rehire_reason' => 'أداء ضعيف موثق', 'notes' => 'ضعف الأداء المتكرر رغم الإنذارات'])->assertCreated()->json('data');
        $this->assertStringStartsWith('TRM-2026-', $t['ref_no']);
        $this->assertSame(['المدير المباشر', 'المدير العام'], array_map(fn ($s) => $s['role']['ar'], $t['steps']));   // HR رفعه فلا يعتمده

        $this->login('hani.samman@alfarada.sy');
        $inbox = collect($this->getJson('/api/me/approvals')->json('data'))->where('kind', 'exit');
        // مدير علاء (المدير العام نفسه أو غيره) ← ثم المدير العام
        foreach (range(1, 2) as $_) {
            $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'exit');
            if (! $row) { $mgr = User::where('employee_id', $alaa->manager_id)->firstOrFail(); $this->login($mgr->email); $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'exit'); }
            $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
            $this->login('hani.samman@alfarada.sy');
            if (Resignation::find($t['id'])->status === 'clearance') break;
        }
        $this->assertSame('clearance', Resignation::find($t['id'])->status);

        // علاء مدير ← لا يُغلق ملفه قبل تحديد البديل، ومدير النظام ينقل الصلاحيات
        $this->login('hr@alfarada.sy');
        $this->assertTrue($this->getJson("/api/hr/offboarding/{$t['id']}")->json('data.needs_successor'));
        $this->postJson("/api/hr/offboarding/{$t['id']}/successor", ['user_id' => User::where('email', 'samer.halabi@alfarada.sy')->value('id')])->assertForbidden();
        $this->login('admin@alfarada.sy');
        $lama = User::where('email', 'lama.azmeh@alfarada.sy')->firstOrFail();
        $res = $this->postJson("/api/hr/offboarding/{$t['id']}/successor", ['user_id' => $lama->id, 'reset' => true])->assertOk()->json();
        $this->assertContains('manager', $res['roles']);
        $this->assertNotEmpty($res['password']);
        $this->assertTrue($lama->fresh()->load('roles')->hasRole('manager'));
        $this->assertTrue((bool) $lama->fresh()->must_change_password);
    }

    public function test_external_notice_needs_documented_consent(): void
    {
        Notification::fake();
        $samer = $this->emp('UHR-1007');
        Tenant::set($samer->company);
        ServiceRequest::create(['employee_id' => $samer->id, 'kind' => 'salary_cert', 'status' => 'approved', 'ref_no' => 'CRT-2026-0101', 'notify_consent' => true,
            'addressee' => ['name' => 'بنك سورية الدولي', 'cr_no' => '1234', 'email' => 'hr@bank.sy', 'phone' => '0111111']]);
        ServiceRequest::create(['employee_id' => $samer->id, 'kind' => 'salary_cert', 'status' => 'approved', 'ref_no' => 'CRT-2026-0102', 'notify_consent' => false,
            'addressee' => ['name' => 'شركة أخرى', 'cr_no' => '99', 'email' => 'x@other.sy', 'phone' => '0222222']]);
        Tenant::forget();
        $id = $this->approvedResignation();
        $this->login('hr@alfarada.sy');
        $n = collect($this->getJson("/api/hr/offboarding/{$id}")->json('data.notices'));
        $this->assertSame(['prepared', 'blocked'], $n->sortBy('id')->pluck('status')->values()->all());
        $blocked = $n->firstWhere('status', 'blocked');
        $this->postJson("/api/hr/offboarding/{$id}/notices/{$blocked['id']}/send")->assertConflict()->assertJsonPath('code', 'notice_blocked');
        $ok = $n->firstWhere('status', 'prepared');
        $this->postJson("/api/hr/offboarding/{$id}/notices/{$ok['id']}/send")->assertOk();
        Notification::assertSentOnDemand(ExitNoticeMail::class, fn ($m, $ch, $notifiable) => $notifiable->routes['mail'] === 'hr@bank.sy');
        $this->assertSame('sent', ExitNotice::find($ok['id'])->status);

        $rep = $this->getJson('/api/hr/offboarding/report?month=2026-10')->assertOk()->json('data');
        $this->assertSame(1, $rep['count']);
        $this->assertSame('offer', $rep['reasons'][0]['key']);
    }

    public function test_exit_lists_and_policy_are_configurable(): void
    {
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/offboarding/settings')->assertOk()->json('data');
        $d['reasons'] = array_values(array_filter($d['reasons'], fn ($x) => $x['key'] !== 'other'));
        $this->putJson('/api/hr/offboarding/settings', $d)->assertUnprocessable()->assertJsonPath('code', 'required_item');
        $d = $this->getJson('/api/hr/offboarding/settings')->json('data');
        $d['reasons'] = array_values(array_filter($d['reasons'], fn ($x) => $x['key'] !== 'travel'));   // مستخدم في طلب مفتوح (بيانات الديمو)
        $this->putJson('/api/hr/offboarding/settings', $d)->assertUnprocessable()->assertJsonPath('code', 'in_use');
        $d = $this->getJson('/api/hr/offboarding/settings')->json('data');
        $d['questions'][] = ['key' => 'culture', 'ar' => 'كيف تصف ثقافة الشركة؟', 'en' => 'How would you describe the culture?'];
        $this->putJson('/api/hr/offboarding/settings', $d)->assertOk();
        $this->assertCount(9, $this->getJson('/api/hr/offboarding/settings')->json('data.questions'));
        $this->postJson('/api/hr/policies', ['key' => 'exit', 'effective_from' => '2026-11-01', 'value' => ['notice_days' => 500, 'decision_days' => 30, 'notice_pay_months' => 2, 'death_grant_months' => 2, 'insurance_days' => 15]])
            ->assertUnprocessable()->assertJsonValidationErrors('notice_days');
    }
}
