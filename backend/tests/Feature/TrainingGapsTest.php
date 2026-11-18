<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TdApproval;
use App\Models\TdCampaign;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdPlan;
use App\Models\TdPlanItem;
use App\Models\TdSession;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** الدفعة 27: إكمال موديول التدريب — القاعات والتعارض، اعتماد العروض، تعديل الخطة المعتمدة، التأجيل والاستبعاد، الروابط، الطباعة، الاستيراد، التقارير */
class TrainingGapsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $samer;
    private User $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        $this->samer = Employee::where('email', 'samer.halabi@uniqueness.sy')->first();
        $this->samer->update(['status' => 'active', 'salary' => 100000, 'currency' => 'USD']);   // بلا تعهد في هذه الاختبارات
        $this->ceo = User::where('employee_id', Employee::where('code', 'UHR-1001')->value('id'))->first();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void { $this->postJson('/api/auth/logout'); $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk(); }
    private function sig(): string { $i = imagecreatetruecolor(240, 80); imageline($i, 10, 60, 230, 20, imagecolorallocate($i, 255, 255, 255)); ob_start(); imagepng($i); return 'data:image/png;base64,'.base64_encode(ob_get_clean()); }

    private function program(string $code, string $delivery = 'external'): int
    {
        return $this->postJson('/api/td/programs', ['code' => $code, 'title' => 'برنامج '.$code, 'path' => 'training', 'delivery' => $delivery, 'audience' => 'staff', 'method' => 'workshop', 'hours' => 6, 'active' => true, 'mandatory' => false, 'has_test' => false])
            ->assertOk()->json('data.0.id');
    }

    private function inboxDecide(User $u, string $kind, bool $ok = true): void
    {
        $this->postJson('/api/auth/logout'); $this->actingAs($u);
        $row = collect($this->getJson('/api/me/approvals')->assertOk()->json('data'))->firstWhere('kind', $kind);
        $this->assertNotNull($row, "no {$kind} row");
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => $ok ? 'approve' : 'reject', 'note' => $ok ? null : 'السعر مرتفع مقارنة بالسوق'])->assertOk();
    }

    public function test_rooms_quotes_postpone_exclude_and_links(): void
    {
        Notification::fake();
        $this->login('hr@uniqueness.sy');
        $room = $this->postJson('/api/td/rooms', ['name' => 'القاعة الكبرى', 'capacity' => 10, 'equipment' => ['projector']])->assertOk()->json('data.0.id');
        $pid = $this->program('EXT-01');
        $meet = [['date' => '2026-10-20', 'from' => '09:00', 'to' => '12:00']];
        $s1 = $this->postJson('/api/td/sessions', ['program_id' => $pid, 'room_id' => $room, 'capacity' => 12, 'currency' => 'USD', 'meetings' => $meet])->assertCreated();
        $this->assertContains('room_small', array_column($s1->json('warnings'), 'key'));
        // FR-TD-075: القاعة نفسها في نفس الوقت مرفوضة، وفي وقت لا يتداخل مقبولة
        $this->postJson('/api/td/sessions', ['program_id' => $pid, 'room_id' => $room, 'capacity' => 8, 'meetings' => [['date' => '2026-10-20', 'from' => '11:00', 'to' => '13:00']]])->assertStatus(422)->assertJsonPath('code', 'room_clash');
        $this->postJson('/api/td/sessions', ['program_id' => $pid, 'room_id' => $room, 'capacity' => 8, 'meetings' => [['date' => '2026-10-20', 'from' => '13:00', 'to' => '15:00']]])->assertCreated();
        $sid = $s1->json('id');

        // BR-05 / F-14: عروض بمعايير موزونة ← اختيار غير الأعلى يحتاج مبرراً ← اعتماد المدير العام قبل التأكيد
        $quotes = [['center' => 'مركز أ', 'price' => 1000, 'currency' => 'USD', 'scores' => ['quality' => 5, 'experience' => 5, 'terms' => 4]],
            ['center' => 'مركز ب', 'price' => 800, 'currency' => 'USD', 'scores' => ['quality' => 3, 'experience' => 3, 'terms' => 3]],
            ['center' => 'مركز ج', 'price' => 1500, 'currency' => 'USD', 'scores' => ['quality' => 4, 'experience' => 4, 'terms' => 4]]];
        $this->putJson("/api/td/sessions/{$sid}", ['program_id' => $pid, 'room_id' => $room, 'capacity' => 10, 'currency' => 'USD', 'meetings' => $meet, 'quotes' => $quotes])->assertOk();
        $d = $this->getJson("/api/td/sessions/{$sid}")->assertOk();
        $this->assertSame(0, $d->json('data.quote_rank.0.index'));   // مركز أ الأعلى رغم أنه ليس الأرخص
        $this->postJson("/api/td/sessions/{$sid}/nominate", ['employee_ids' => [$this->samer->id]])->assertOk();
        $en = TdEnrollment::allCompanies()->where('session_id', $sid)->first();
        $this->postJson("/api/td/enrollments/{$en->id}/confirm")->assertStatus(409)->assertJsonPath('code', 'quote_not_approved');
        $this->postJson("/api/td/sessions/{$sid}/quote", ['choice' => 1])->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/td/sessions/{$sid}/quote", ['choice' => 1, 'reason' => 'العرض الأرخص مناسب لهذه الدفعة'])->assertOk()->assertJsonPath('status', 'pending_exec');
        $this->inboxDecide($this->ceo, 'td_approval');
        $s = TdSession::allCompanies()->find($sid);
        $this->assertSame('approved', $s->quote_status);
        $this->assertSame(800.0, (float) collect($s->costs)->firstWhere('type', 'center')['amount']);

        // رابط موافقة المدير بالبريد (FR-TD-065)
        $mgr = User::where('employee_id', $this->samer->manager_id)->first();
        Notification::assertSentTo($mgr, \App\Notifications\TrainingMail::class, function ($n) use (&$link) { $link = $n->url; return str_contains((string) $n->url, '/td-approve/'); });
        $token = basename($link);
        $this->postJson('/api/auth/logout');
        $this->getJson("/api/public/td-approve/{$token}")->assertOk()->assertJsonPath('data.answered', false);
        $this->postJson("/api/public/td-approve/{$token}", ['ok' => true])->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson("/api/public/td-approve/{$token}")->assertStatus(410);

        // التأكيد بعد الاعتماد، ثم التأجيل بلا موعد ← «مؤجل» وتُزال أيام التدريب، ثم موعد جديد
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/td/enrollments/{$en->id}/confirm")->assertOk();
        $this->assertSame('confirmed', $en->fresh()->status);
        $this->postJson("/api/td/sessions/{$sid}/postpone", ['reason' => 'قصير'])->assertStatus(422);
        $this->postJson("/api/td/sessions/{$sid}/postpone", ['reason' => 'تعارض مع إغلاق السنة المالية'])->assertOk()->assertJsonPath('status', 'postponed');
        $this->assertFalse(\App\Models\AttendanceDay::allCompanies()->where('employee_id', $this->samer->id)->whereDate('date', '2026-10-20')->where('status', 'training')->exists());
        $this->postJson("/api/td/sessions/{$sid}/postpone", ['reason' => 'الموعد الجديد بعد الإغلاق', 'meetings' => [['date' => '2026-11-03', 'from' => '09:00', 'to' => '12:00']]])->assertOk()->assertJsonPath('starts_on', '2026-11-03');
        $this->assertSame('training', \App\Models\AttendanceDay::allCompanies()->where('employee_id', $this->samer->id)->whereDate('date', '2026-11-03')->value('status'));

        // QR: خارج نافذة الجلسة مرفوض
        Carbon::setTestNow(Carbon::parse('2026-11-03 07:00:00', 'Asia/Damascus'));
        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/me/training/checkin/{$s->survey_token}")->assertStatus(409)->assertJsonPath('code', 'checkin_closed');
        Carbon::setTestNow(Carbon::parse('2026-11-03 09:05:00', 'Asia/Damascus'));
        $this->postJson("/api/me/training/checkin/{$s->survey_token}")->assertOk()->assertJsonPath('data.status', 'present');
        // رابط تقويم للقراءة فقط
        $url = $this->getJson('/api/me/training/calendar-link')->assertOk()->json('url');
        $this->postJson('/api/auth/logout');
        $ics = $this->get('/api/public/td-calendar/'.basename($url, '.ics').'.ics')->assertOk()->getContent();
        $this->assertStringContainsString('20261103T090000', $ics);

        // الاستبعاد بسبب ← يُرقّى من في الانتظار
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/td/enrollments/{$en->id}/exclude", ['reason' => 'مخالفة قواعد السلوك في القاعة'])->assertOk()->assertJsonPath('status', 'excluded');
    }

    public function test_plan_change_approval_history_prints_import_reports_and_commit_link(): void
    {
        Notification::fake();
        $this->login('hr@uniqueness.sy');
        $cid = Tenant::run($this->samer->company_id, fn () => \App\Models\Company::find($this->samer->company_id)->id);
        // خطة معتمدة مباشرة
        $p = Tenant::run($cid, fn () => TdPlan::create(['year' => 2027, 'title' => 'خطة 2027', 'scope' => 'company', 'budget' => 10000, 'currency' => 'USD', 'status' => 'approved']));
        $item = Tenant::run($cid, fn () => TdPlanItem::create(['plan_id' => $p->id, 'title' => 'التفاوض', 'path' => 'training', 'delivery' => 'external', 'month' => 3, 'estimated_cost' => 1500, 'status' => 'planned']));
        $this->putJson("/api/td/plan-items/{$item->id}", ['title' => 'التفاوض', 'path' => 'training', 'delivery' => 'external', 'month' => 5, 'estimated_cost' => 2000, 'change_reason' => 'زيادة عدد المتدربين'])->assertOk()
            ->assertJsonPath('data.change_requests.0.status', 'pending_exec');
        $this->assertSame(3, (int) $item->fresh()->month);   // BR-18: لا يُطبَّق قبل الاعتماد
        $this->assertSame('pending', $item->fresh()->changes[0]['status']);
        $this->putJson("/api/td/plan-items/{$item->id}", ['title' => 'التفاوض', 'path' => 'training', 'delivery' => 'external', 'month' => 6, 'estimated_cost' => 2000, 'change_reason' => 'تعديل آخر للبند'])->assertStatus(409);
        $this->inboxDecide($this->ceo, 'td_approval');
        $this->assertSame([5, 2000.0, 'approved'], [(int) $item->fresh()->month, (float) $item->fresh()->estimated_cost, $item->fresh()->changes[0]['status']]);
        // بند جديد بعد الاعتماد ← يُنشأ عند الاعتماد
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/td/plans/{$p->id}/items", ['title' => 'السلامة المهنية', 'path' => 'training', 'delivery' => 'internal', 'month' => 4, 'estimated_cost' => 300, 'change_reason' => 'متطلب تنظيمي جديد'])->assertOk();
        $this->assertSame(1, $p->items()->count());
        $this->inboxDecide($this->ceo, 'td_approval');
        $this->assertSame(2, $p->items()->count());

        // السجل السابق للنظام يظهر في F-06 ويدخل الساعات
        $this->login('hr@uniqueness.sy');
        $this->postJson('/api/td/history', ['employee_id' => $this->samer->id, 'title' => 'إدارة المشاريع', 'provider' => 'معهد خارجي', 'from_on' => '2024-02-01', 'to_on' => '2024-02-05', 'hours' => 20])->assertOk()->assertJsonCount(1, 'data');
        $rec = $this->getJson("/api/td/employees/{$this->samer->id}/record")->assertOk();
        $this->assertSame(20.0, (float) $rec->json('data.totals.hours_before') ?: (float) $rec->json('totals.hours_before'));

        // الطباعة: النماذج تُبنى، والموظف يطبع سجله فقط
        $this->getJson("/api/td/print/TD4/{$p->id}")->assertOk()->assertJsonPath('data.code', 'TD4');
        $this->getJson("/api/td/print/TD6/{$this->samer->id}")->assertOk();
        $pid = $this->program('INT-01', 'internal');
        $sid = $this->postJson('/api/td/sessions', ['program_id' => $pid, 'capacity' => 10, 'meetings' => [['date' => '2026-10-20', 'from' => '09:00', 'to' => '12:00']]])->assertCreated()->json('id');
        foreach (['TD7', 'TD8', 'TD13', 'TD14'] as $f) $this->getJson("/api/td/print/{$f}/{$sid}")->assertOk();
        $this->login('samer.halabi@uniqueness.sy');
        $this->getJson("/api/me/training/print/TD6/{$this->samer->id}")->assertOk();
        $other = Employee::where('id', '!=', $this->samer->id)->whereNull('manager_id')->value('id');
        $this->getJson("/api/me/training/print/TD6/{$other}")->assertForbidden();

        // استيراد الاحتياجات من Excel: خطأ ← لا شيء، صحيح ← إدخال
        $this->login('hr@uniqueness.sy');
        $file = $this->xlsx([['employee_code', 'title', 'path', 'priority', 'cause'], [$this->samer->code, 'إعداد التقارير', 'training', 'high', 'knowledge'], ['UHR-9999', 'x', 'bad', '', '']]);
        $pv = $this->post('/api/td/needs/import/preview', ['file' => $file])->assertOk();
        $this->assertSame([1, 1], [$pv->json('ok'), $pv->json('bad')]);
        $this->post('/api/td/needs/import', ['file' => $this->xlsx([['employee_code', 'title', 'path', 'priority', 'cause'], [$this->samer->code, 'إعداد التقارير', 'training', 'high', 'knowledge']])])->assertOk()->assertJsonPath('imported', 1);

        // التقارير
        foreach (['scorecard', 'costs', 'impacts', 'stage'] as $k) $this->getJson("/api/td/reports/{$k}")->assertOk();
        $this->get('/api/td/export/costs')->assertOk();

        // حملة: تذكير ثم إغلاق تلقائي
        $c = $this->postJson('/api/td/campaigns', ['title' => 'حملة 2027', 'year' => 2027, 'closes_on' => '2026-10-14'])->assertOk();
        Tenant::run($cid, fn () => app(\App\Services\Training\TdExtras::class)->sweep());
        $camp = TdCampaign::allCompanies()->latest('id')->first();
        $this->assertNotNull($camp->reminded_at);
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Damascus'));
        Tenant::run($cid, fn () => app(\App\Services\Training\TdExtras::class)->sweep());
        $this->assertSame('closed', $camp->fresh()->status);

        // توقيع التعهد عن بعد: رابط + رمز بالبريد
        $c = Tenant::run($cid, fn () => TdCommitment::create(['employee_id' => $this->samer->id, 'title' => 'دورة خارجية', 'cost' => 900, 'currency' => 'USD', 'months' => 6, 'mode' => 'prorata', 'status' => 'generated', 'body' => 'نص التعهد']));
        $token = Tenant::run($cid, fn () => \App\Services\Training\TdExtras::commitLink($c));
        $this->postJson('/api/auth/logout');
        $this->getJson("/api/public/td-commit/{$token}")->assertOk()->assertJsonPath('data.signed', false);
        $this->postJson("/api/public/td-commit/{$token}", ['code' => '000000', 'signature' => $this->sig()])->assertStatus(422)->assertJsonPath('code', 'code_required');
        $this->postJson("/api/public/td-commit/{$token}/code")->assertOk();
        $samerUser = User::where('employee_id', $this->samer->id)->first();
        $code = null;
        Notification::assertSentTo($samerUser, \App\Notifications\TrainingMail::class, function ($n) use (&$code) { if ($n->ref) $code = $n->ref; return (bool) $n->ref; });
        $this->postJson("/api/public/td-commit/{$token}", ['code' => '111111', 'signature' => $this->sig()])->assertStatus(422)->assertJsonPath('code', 'wrong');
        $this->postJson("/api/public/td-commit/{$token}", ['code' => $code, 'signature' => $this->sig()])->assertOk();
        $this->assertSame('active', $c->fresh()->status);

        // بقية النماذج تُبنى دون أخطاء
        $this->login('hr@uniqueness.sy');
        $this->getJson("/api/td/print/TD3/{$c->id}")->assertOk();
        $this->getJson('/api/td/print/TD2/'.\App\Models\TdNeed::allCompanies()->value('id'))->assertOk();
        $idp = \App\Models\DevelopmentPlan::allCompanies()->where('employee_id', $this->samer->id)->value('id');
        $this->getJson("/api/td/print/TD11/{$idp}")->assertOk();
        $this->postJson('/api/td/qualifications', ['employee_id' => $this->samer->id])->assertOk();
        $this->getJson('/api/td/print/TD1/'.\App\Models\TdQualification::allCompanies()->value('id'))->assertOk();
        $cert = Tenant::run($cid, fn () => app(\App\Services\Training\Sessions::class)->issue(['employee_id' => $this->samer->id, 'type' => 'completion', 'title' => 'دورة']));
        $this->getJson("/api/td/print/TDC/{$cert->id}")->assertOk()->assertJsonPath('data.certificate', true);
        $cbp = $this->postJson('/api/td/cbps', ['title' => 'بناء القدرات', 'goals' => [['title' => 'هدف', 'capacities' => [], 'initiatives' => [['title' => 'مبادرة', 'owner' => 'الموارد', 'kpi' => '5 مدربين']]]]])->assertOk();
        $this->getJson('/api/td/print/CBP/'.\App\Models\TdCbp::allCompanies()->value('id'))->assertOk();
    }

    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer(); $w->openToFile($path);
        foreach ($rows as $r) $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($r));
        $w->close();
        return new UploadedFile($path, 'needs.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
