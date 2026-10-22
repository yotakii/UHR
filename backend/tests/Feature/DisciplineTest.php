<?php

namespace Tests\Feature;

use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\Notice;
use App\Models\ViolationType;
use App\Services\Payroll\MonthCalc;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** M17 المخالفات والجزاءات (SRS §10.7): الدليل، المسار (فتح ← أقوال ← تحقيق ← قرار)، الدرجة والتخفيف، المدير العام، سقف الشهر، المهلة، الإقرار، الشكاوى */
class DisciplineTest extends TestCase
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

    private function type(string $titleStart): ViolationType
    {
        return ViolationType::allCompanies()->where('company_id', $this->emp('UHR-1007')->company_id)->where('title_ar', 'like', $titleStart.'%')->orderBy('id')->firstOrFail();
    }

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** يفتح حالة من HR ويسجّل التحقيق، ويعيد معرّفها */
    private function investigated(string $code, ViolationType $t, string $on = '2026-10-11'): int
    {
        $id = $this->postJson('/api/hr/discipline', ['employee_id' => $this->emp($code)->id, 'violation_type_id' => $t->id, 'occurred_on' => $on, 'description' => 'وصف المخالفة'])->assertCreated()->json('data.id');
        $this->postJson("/api/hr/discipline/{$id}/investigate", ['statement' => 'أقوال الموظف في المحضر', 'witnesses' => 'رئيس القسم', 'union_notified' => true])->assertOk()->assertJsonPath('data.status', 'investigated');
        return $id;
    }

    public function test_catalog_is_seeded_from_the_company_table_and_editable(): void
    {
        $company = $this->emp('UHR-1007')->company_id;
        $counts = ViolationType::allCompanies()->where('company_id', $company)->selectRaw('"group", count(*) n')->groupBy('group')->pluck('n', 'group')->all();
        $this->assertEquals(['time' => 10, 'work' => 18, 'conduct' => 13], $counts);
        $late = $this->type('التأخر عن مواعيد العمل لمدة تصل إلى 15');
        $this->assertEquals([['kind' => 'warning'], ['kind' => 'deduct', 'days' => 0.25], ['kind' => 'deduct', 'days' => 0.5], ['kind' => 'deduct', 'days' => 1]], $late->penalties);

        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/discipline')->assertForbidden();
        $this->login('hr@alfarada.sy');
        $cat = $this->getJson('/api/hr/discipline/catalog')->assertOk()->json();
        $this->assertCount(41, $cat['data']);
        $row = ['group' => 'work', 'title_ar' => 'استخدام الهاتف أثناء الاجتماعات', 'title_en' => 'Phone use in meetings', 'penalties' => [['kind' => 'deduct', 'days' => 1], ['kind' => 'warning']]];
        $this->postJson('/api/hr/discipline/catalog', $row)->assertUnprocessable()->assertJsonPath('code', 'not_ascending');
        $new = $this->postJson('/api/hr/discipline/catalog', ['penalties' => [['kind' => 'note'], ['kind' => 'warning'], ['kind' => 'deduct', 'days' => 0.5]]] + $row)->assertCreated()->json('data');
        $this->assertSame(19, $new['number']);
        $this->assertSame('حسم أجر نصف يوم', $new['penalties'][2]['label']['ar']);
    }

    public function test_manager_opens_employee_defends_hr_investigates_and_decides_then_acknowledgement(): void
    {
        $t = $this->type('التأخر عن مواعيد العمل لمدة تصل إلى 15');
        $samer = $this->emp('UHR-1007');
        $this->login('alaa.sheikhani@alfarada.sy');   // مدير سامر
        $mine = $this->getJson('/api/me/discipline')->assertOk()->json();
        $this->assertNotEmpty($mine['team']);
        $this->postJson('/api/me/discipline', ['employee_id' => $this->emp('UHR-1003')->id, 'violation_type_id' => $t->id, 'occurred_on' => '2026-10-11', 'description' => 'تأخر صباحي'])->assertNotFound();   // ليس من فريقه
        $this->postJson('/api/me/discipline', ['employee_id' => $samer->id, 'violation_type_id' => $t->id, 'occurred_on' => '2026-10-20', 'description' => 'تأخر صباحي'])->assertUnprocessable()->assertJsonPath('code', 'future');
        $this->postJson('/api/me/discipline', ['employee_id' => $samer->id, 'violation_type_id' => $t->id, 'occurred_on' => '2026-10-11', 'description' => 'تأخر صباحي'])->assertOk();
        $c = DisciplineCase::allCompanies()->where('employee_id', $samer->id)->firstOrFail();
        $this->assertSame('open', $c->status);
        $this->assertSame('2026-10-27', $c->deadline->toDateString());   // 15 يوماً من الاكتشاف
        $this->assertSame(['kind' => 'warning'], $c->suggested);
        $this->assertStringStartsWith('DSC-2026-', $c->ref_no);
        $this->assertTrue(Notice::allCompanies()->where('link', 'discipline:'.$c->id)->exists());   // HR يُبلَّغ بحالة فتحها المدير

        $this->login('samer.halabi@alfarada.sy');
        $d = $this->getJson('/api/me/discipline')->assertOk()->json();
        $this->assertSame($c->ref_no, $d['data'][0]['ref_no']);
        $this->assertSame([], $d['team']);
        $this->postJson("/api/me/discipline/{$c->id}/defense", ['defense' => 'كان هناك ازدحام على الطريق'])->assertOk();
        $this->postJson("/api/me/discipline/{$c->id}/acknowledge", ['signature' => self::signature()])->assertConflict()->assertJsonPath('code', 'not_decided');

        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/discipline/{$c->id}/decide", ['kind' => 'warning'])->assertConflict()->assertJsonPath('code', 'investigation_required');
        $full = $this->getJson("/api/hr/discipline/{$c->id}")->assertOk()->json('data');
        $this->assertSame('كان هناك ازدحام على الطريق', $full['defense']);
        $this->postJson("/api/hr/discipline/{$c->id}/investigate", ['statement' => 'أقرّ بالتأخير'])->assertOk();
        $this->postJson("/api/hr/discipline/{$c->id}/decide", ['kind' => 'deduct', 'days' => 1])->assertUnprocessable()->assertJsonPath('code', 'over_grade');
        $this->postJson("/api/hr/discipline/{$c->id}/decide", ['kind' => 'note'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');   // تخفيف بلا سبب
        $r = $this->postJson("/api/hr/discipline/{$c->id}/decide", ['kind' => 'warning'])->assertOk()->json('data');
        $this->assertSame('decided', $r['status']);
        $this->assertSame('إنذار كتابي', $r['penalty']['label']['ar']);
        $this->getJson("/api/hr/discipline/{$c->id}/warning")->assertOk()->assertJsonPath('data.ref_no', $c->ref_no);

        $this->login('samer.halabi@alfarada.sy');
        $this->postJson("/api/me/discipline/{$c->id}/defense", ['defense' => 'إضافة'])->assertConflict();
        $this->postJson("/api/me/discipline/{$c->id}/acknowledge", ['signature' => self::signature()])->assertOk();
        $this->assertNotNull($c->fresh()->acknowledged_at);
        $this->assertNull($this->getJson('/api/me/discipline')->json('data.0.investigation.statement'));   // المحضر للموارد البشرية

        // تكرار خلال 6 أشهر ← الدرجة الثانية
        $this->login('hr@alfarada.sy');
        $id = $this->investigated('UHR-1007', $t);
        $this->assertSame(2, DisciplineCase::find($id)->occurrence);
        $this->assertEquals(['kind' => 'deduct', 'days' => 0.25], DisciplineCase::find($id)->suggested);
    }

    public function test_heavier_penalty_goes_to_the_general_manager_and_the_monthly_cap_applies(): void
    {
        $this->login('hr@alfarada.sy');
        $heavy = $this->type('استعمال مكان العمل');   // أول مرة حسم 5 أيام > صلاحية HR (3)
        $id = $this->investigated('UHR-1007', $heavy);
        $r = $this->postJson("/api/hr/discipline/{$id}/decide", ['kind' => 'deduct', 'days' => 5])->assertOk()->json('data');
        $this->assertSame('pending_gm', $r['status']);
        $this->assertSame('المدير العام', $r['approval']['steps'][0]['role']['ar']);
        $this->postJson("/api/hr/discipline/{$id}/decide", ['kind' => 'warning', 'reason' => 'تخفيف بعد المراجعة'])->assertConflict()->assertJsonPath('code', 'pending_gm');

        $this->login('hani.samman@alfarada.sy');   // الرئيس التنفيذي
        $inbox = $this->getJson('/api/me/approvals')->assertOk()->json('data');
        $row = collect($inbox)->firstWhere('kind', 'penalty');
        $this->assertNotNull($row);
        $this->assertSame('حسم أجر 5 أيام', $row['penalty']['label']['ar']);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $c = DisciplineCase::find($id);
        $this->assertSame('decided', $c->status);
        $this->assertEquals(5.0, $c->deduct_days);
        $this->assertSame('2026-10', $c->month);

        // سقف 5 أيام بالشهر: الحسم التالي يصبح صفراً
        $this->login('hr@alfarada.sy');
        $fight = $this->type('التشاجر مع الزملاء');
        $id2 = $this->investigated('UHR-1007', $fight);
        $r2 = $this->postJson("/api/hr/discipline/{$id2}/decide", ['kind' => 'deduct', 'days' => 1])->assertOk()->json('data');
        $this->assertTrue($r2['capped']);
        $this->assertEquals(0, $r2['deduct_days']);

        $samer = $this->emp('UHR-1007');
        Tenant::set($samer->company);
        $m = MonthCalc::compute($samer, '2026-10');
        $this->assertEquals(5.0, $m['data']['discipline_days']);
        $this->assertEqualsWithDelta(5 * $m['money']['day'], $m['money']['discipline'], 0.05);

        // رفض المدير العام ← تعود الحالة للموارد البشرية
        $id3 = $this->investigated('UHR-1002', $heavy);
        $this->postJson("/api/hr/discipline/{$id3}/decide", ['kind' => 'dismissal'])->assertUnprocessable()->assertJsonPath('code', 'over_grade');
        $this->postJson("/api/hr/discipline/{$id3}/decide", ['kind' => 'deduct', 'days' => 5])->assertOk();
        $this->login('hani.samman@alfarada.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'penalty');
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'reject', 'note' => 'يكفي إنذار لأول مرة'])->assertOk();
        $this->assertSame('investigated', DisciplineCase::find($id3)->status);
    }

    public function test_deadline_expiry_dismissal_and_attendance_suggestions(): void
    {
        $this->login('hr@alfarada.sy');
        $t = $this->type('ترك العمل أو الانصراف');
        $id = $this->investigated('UHR-1009', $t);
        $this->postJson("/api/hr/discipline/{$id}/dismiss", ['reason' => 'قصير'])->assertUnprocessable();
        $this->postJson("/api/hr/discipline/{$id}/dismiss", ['reason' => 'ثبت وجود إذن شفهي من المدير'])->assertOk()->assertJsonPath('data.status', 'dismissed');

        $id2 = $this->investigated('UHR-1009', $t);
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'Asia/Damascus'));
        $this->postJson("/api/hr/discipline/{$id2}/decide", ['kind' => 'deduct', 'days' => 0.25])->assertConflict()->assertJsonPath('code', 'deadline_passed');
        $this->artisan('discipline:expire')->assertSuccessful();
        $this->assertSame('expired', DisciplineCase::find($id2)->status);

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $s = $this->getJson('/api/hr/discipline/suggestions')->assertOk()->json('data');
        foreach ($s as $x) $this->assertContains($x['type']['auto'], ['late15', 'late30', 'late60', 'late_over', 'absent', 'early']);
        if ($s) {
            $x = $s[0];
            $this->postJson('/api/hr/discipline', ['employee_id' => $x['employee']['id'], 'violation_type_id' => $x['type']['id'], 'occurred_on' => $x['occurred_on'], 'description' => $x['summary']['ar'], 'source' => 'attendance'])->assertCreated();
            $after = collect($this->getJson('/api/hr/discipline/suggestions')->json('data'));
            $this->assertFalse($after->contains(fn ($y) => $y['employee']['id'] === $x['employee']['id'] && $y['type']['id'] === $x['type']['id']));
        }
        $this->postJson('/api/hr/policies', ['key' => 'discipline', 'effective_from' => '2026-11-01', 'value' => ['cap_days' => 99, 'deadline_days' => 15, 'repeat_months' => 6, 'hr_max_days' => 3, 'suggest_late' => 3, 'suggest_absent' => 1, 'suggest_early' => 2]])
            ->assertUnprocessable()->assertJsonValidationErrors('cap_days');
    }

    public function test_complaints_are_confidential_to_hr(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/me/complaints', ['subject' => 'ضغط عمل', 'body' => 'قصير'])->assertUnprocessable();
        $this->postJson('/api/me/complaints', ['subject' => 'توزيع المهام', 'body' => 'توزيع المهام في القسم غير عادل منذ شهرين', 'against' => 'رئيس القسم'])->assertOk()
            ->assertJsonPath('complaints.0.status', 'received');
        $id = $this->getJson('/api/me/discipline')->json('complaints.0.id');
        $n = Notice::allCompanies()->where('link', 'complaint:'.$id)->firstOrFail();
        $this->assertStringNotContainsString('سامر', $n->text_ar);

        $this->login('alaa.sheikhani@alfarada.sy');   // المدير لا يراها
        $this->getJson('/api/hr/discipline/complaints')->assertForbidden();

        $this->login('hr@alfarada.sy');
        $this->assertSame('توزيع المهام', $this->getJson('/api/hr/discipline/complaints')->assertOk()->json('data.0.subject'));
        $this->postJson("/api/hr/discipline/complaints/{$id}", ['action' => 'review'])->assertOk()->assertJsonPath('data.status', 'reviewing');
        $this->postJson("/api/hr/discipline/complaints/{$id}", ['action' => 'close'])->assertUnprocessable()->assertJsonPath('code', 'response_required');
        $this->postJson("/api/hr/discipline/complaints/{$id}", ['action' => 'close', 'response' => 'تمت مراجعة التوزيع مع مدير القسم وتعديله'])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->login('samer.halabi@alfarada.sy');
        $this->assertSame('تمت مراجعة التوزيع مع مدير القسم وتعديله', $this->getJson('/api/me/discipline')->json('complaints.0.response'));
    }
}
