<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Mission;
use App\Models\MonthSheet;
use App\Models\PayLine;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 20 — المهمات والسفر والمكافآت وبنود الراتب (الجزء B: AC-TM-01..04) */
class MissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        $this->uq(function () {
            Employee::where('email', 'samer.halabi@uniqueness.sy')->update(['grade_id' => Codes::resolve('grades', 'G03')->id]);
            ExchangeRate::create(['date' => '2026-10-01', 'from' => 'USD', 'to' => 'SYP', 'rate' => 10000]);
        });
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function uq(callable $fn): mixed { return Tenant::run(User::where('email', 'hr@uniqueness.sy')->value('company_id'), $fn); }
    private function type(string $c): int { return $this->uq(fn () => Codes::resolve('mission_types', $c)->id); }
    private function approveAll(int $id, array $who): void
    {
        foreach ($who as $email) {
            $this->login($email);
            $row = collect($this->getJson('/api/me/approvals')->assertOk()->json('data'))->first(fn ($r) => $r['kind'] === 'mission' && $r['id'] === $id);
            $this->assertNotNull($row, "no step for {$email}");
            $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        }
    }

    public function test_governorate_rule_reclassifies_and_gives_no_allowance(): void
    {
        $this->login('samer.halabi@uniqueness.sy');
        $m = $this->postJson('/api/missions', ['type_id' => $this->type('MT-30'), 'title' => 'زيارة عميل في ريف دمشق', 'travel' => ['scope' => 'local', 'governorate' => 'RDM', 'city' => 'دوما',
            'purpose' => 'متابعة عقد', 'depart_on' => '2026-10-20', 'return_on' => '2026-10-21', 'transport' => 'car', 'km' => 60]])->assertCreated()->json('data');
        $this->assertSame('MT-20', $m['type']['code']);   // AC-TM-01
        $this->assertSame('MT-30', $m['travel']['reclassified']);
    }

    public function test_travel_mission_from_request_to_pay_lines_with_advance_and_hotel_limit(): void
    {
        $samer = Employee::allCompanies()->where('email', 'samer.halabi@uniqueness.sy')->firstOrFail();
        $this->login('samer.halabi@uniqueness.sy');
        $id = $this->postJson('/api/missions', ['type_id' => $this->type('MT-30'), 'title' => 'تركيب نظام لفرع حلب', 'advance' => 500000, 'advance_currency' => 'SYP',
            'travel' => ['scope' => 'local', 'governorate' => 'ALP', 'city' => 'حلب', 'purpose' => 'تركيب وتدريب', 'depart_on' => '2026-10-14', 'return_on' => '2026-10-16', 'transport' => 'road']])->assertCreated()->json('data.id');
        $this->unlockSalaries(false);
        $est = $this->getJson("/api/missions/{$id}")->assertOk()->json('data.estimate');
        $this->assertSame([3, 'E-110', 300000], [(int) $est['days'], $est['lines'][0]['code'], (int) $est['lines'][0]['amount']]);
        $this->postJson("/api/missions/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_manager');
        // المدير ← HR ← المالية ← الرئيس التنفيذي
        $this->approveAll($id, ['alaa.sheikhani@uniqueness.sy', 'hr@uniqueness.sy', 'basel.atassi@uniqueness.sy', 'hani.samman@uniqueness.sy']);
        $m = Mission::allCompanies()->findOrFail($id);
        $this->assertSame('assigned', $m->status);
        $this->assertSame('mission', AttendanceDay::where('employee_id', $samer->id)->whereDate('date', '2026-10-15')->value('status'));   // BR-TM-12

        $this->login('basel.atassi@uniqueness.sy');
        $this->postJson("/api/missions/{$id}/pay-advance", ['voucher_no' => 'PV-2026-118'])->assertOk()->assertJsonPath('data.advance.status', 'paid');

        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/missions/{$id}/progress", ['stages' => [true, true, true, false, false]])->assertOk()->assertJsonPath('data.progress', 70);
        $this->postJson("/api/missions/{$id}/request-close", ['report' => 'تم التركيب وتدريب الفريق'])->assertUnprocessable()->assertJsonPath('code', 'proof_required');
        $proof = $this->post('/api/missions/files', ['file' => UploadedFile::fake()->create('report.pdf', 40, 'application/pdf')])->assertCreated()->json('id');
        $this->postJson("/api/missions/{$id}/request-close", ['report' => 'تم التركيب وتدريب الفريق', 'files' => [$proof]])->assertOk()->assertJsonPath('data.status', 'review');
        $this->login('alaa.sheikhani@uniqueness.sy');
        $this->postJson("/api/missions/{$id}/review", ['accept' => true, 'rating' => 5])->assertOk()->assertJsonPath('data.status', 'done');

        $this->login('samer.halabi@uniqueness.sy');
        $inv = $this->post('/api/missions/files', ['file' => UploadedFile::fake()->create('hotel.pdf', 40, 'application/pdf')])->json('id');
        $this->postJson("/api/missions/{$id}/settlement", ['expenses' => [['code' => 'E-130', 'amount' => 260000, 'currency' => 'SYP', 'nights' => 1, 'file_id' => $inv]]])->assertOk()->assertJsonPath('data.status', 'settling');

        $this->login('hr@uniqueness.sy');
        $this->unlockSalaries();
        $ent = $this->getJson("/api/missions/{$id}")->json('data.entitlements');
        $hotel = collect($ent['lines'])->firstWhere('code', 'E-130');
        $this->assertSame([200000, true], [(int) $hotel['amount'], $hotel['over']]);   // AC-TM-03
        $this->postJson("/api/missions/{$id}/audit", ['note' => 'مطابق'])->assertOk();
        $this->postJson("/api/missions/{$id}/audit", [])->assertForbidden();   // المرحلة الثانية للمالية
        $this->login('basel.atassi@uniqueness.sy');
        $this->unlockSalaries();
        $this->postJson("/api/missions/{$id}/audit", ['note' => 'معتمد'])->assertOk()->assertJsonPath('data.status', 'posted');

        // AC-TM-02: بنود الراتب برقم المهمة في فترة الشهر
        $lines = PayLine::allCompanies()->where('source_type', 'mission')->where('source_id', $id)->get()->keyBy('element_code');
        $this->assertSame([300000.0, 'SYP', '2026-10'], [$lines['E-110']->amount, $lines['E-110']->currency, $lines['E-110']->period]);
        $this->assertSame([500000.0, 'deduction'], [$lines['D-310']->amount, $lines['D-310']->kind]);
        $this->assertEqualsWithDelta(30.0, $lines['E-110']->amount_pay, 0.01);   // بعملة راتب الموظف (USD) بسعر الصرف المحفوظ
        $calc = $this->uq(fn () => \App\Services\Payroll\MonthCalc::compute(Employee::find($samer->id), '2026-10', '2026-10-31')['money']);
        $this->assertEqualsWithDelta(50.0, $calc['allowances'], 0.01);   // 300000 + 200000 فندق = 500000 ل.س = 50 $
        $this->assertEqualsWithDelta(50.0, $calc['line_deductions'], 0.01);

        // BR-TM-04: بعد الترحيل القفل والتصحيح ببند معاكس
        $this->uq(function () { $s = MonthSheet::create(['month' => '2026-10', 'status' => 'closed']); \App\Services\Missions\PayLines::postMonth($s); });
        $this->assertSame('posted', $lines['E-110']->fresh()->status);
        $this->assertSame('closed', Mission::allCompanies()->find($id)->status);
        $this->login('hr@uniqueness.sy');
        $rev = $this->postJson("/api/missions/pay-lines/{$lines['E-110']->id}/reverse", ['reason' => 'خطأ في عدد الأيام'])->assertCreated()->json('id');
        $this->assertSame([-300000.0, '2026-11'], [PayLine::allCompanies()->find($rev)->amount, PayLine::allCompanies()->find($rev)->period]);
    }

    public function test_non_employee_gets_no_pay_lines_and_unclosed_travel_blocks_a_new_one(): void
    {
        $this->uq(fn () => Employee::where('email', 'samer.halabi@uniqueness.sy')->update(['worker_category_id' => Codes::resolve('worker_categories', 'EXP')->id]));
        $this->login('samer.halabi@uniqueness.sy');
        $id = $this->postJson('/api/missions', ['type_id' => $this->type('MT-30'), 'title' => 'استشارة في حمص', 'travel' => ['scope' => 'local', 'governorate' => 'HMS', 'purpose' => 'تقييم', 'depart_on' => '2026-10-13', 'return_on' => '2026-10-13']])->json('data.id');
        $this->postJson("/api/missions/{$id}/submit")->assertOk();
        $this->approveAll($id, ['alaa.sheikhani@uniqueness.sy', 'hr@uniqueness.sy', 'basel.atassi@uniqueness.sy', 'hani.samman@uniqueness.sy']);
        // مهلة الإغلاق تجاوزت ← لا مهمة سفر جديدة (BR-TM-09)
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00', 'Asia/Damascus'));
        $this->login('samer.halabi@uniqueness.sy');
        $id2 = $this->postJson('/api/missions', ['type_id' => $this->type('MT-30'), 'title' => 'زيارة ثانية', 'travel' => ['scope' => 'local', 'governorate' => 'LAT', 'purpose' => 'متابعة', 'depart_on' => '2026-11-03', 'return_on' => '2026-11-04']])->json('data.id');
        $this->postJson("/api/missions/{$id2}/submit")->assertStatus(409)->assertJsonPath('code', 'unclosed_mission');
        $this->artisan('missions:sweep')->assertSuccessful();
        // AC-TM-04: إغلاق بلا بنود راتب لغير الموظف
        $this->postJson("/api/missions/{$id}/request-close", ['report' => 'أنجزت التقييم المطلوب', 'files' => [$this->post('/api/missions/files', ['file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')])->json('id')]])->assertOk();
        $this->login('alaa.sheikhani@uniqueness.sy');
        $this->postJson("/api/missions/{$id}/review", ['accept' => true])->assertOk();
        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/missions/{$id}/settlement", [])->assertOk();
        $this->login('hr@uniqueness.sy'); $this->unlockSalaries();
        $this->postJson("/api/missions/{$id}/audit", [])->assertOk();
        $this->login('basel.atassi@uniqueness.sy'); $this->unlockSalaries();
        $this->postJson("/api/missions/{$id}/audit", [])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame(0, PayLine::allCompanies()->where('source_id', $id)->count());
    }

    public function test_bonus_with_cap_duplicate_reference_and_approval_to_pay_line(): void
    {
        $samer = Employee::allCompanies()->where('email', 'samer.halabi@uniqueness.sy')->firstOrFail();
        $type = $this->uq(function () { $t = Codes::resolve('bonus_types', 'BN-30'); $t->update(['attrs' => array_merge($t->attrs, ['cap' => 200])]); Codes::bump(); return $t->id; });
        $this->login('hr@uniqueness.sy');
        $this->postJson('/api/missions/bonuses', ['type_id' => $type, 'employee_ids' => [$samer->id], 'amount' => 300, 'currency' => 'USD', 'reason' => 'إنجاز استثنائي'])->assertUnprocessable()->assertJsonPath('code', 'over_cap');
        $b = $this->postJson('/api/missions/bonuses', ['type_id' => $type, 'employee_ids' => [$samer->id], 'amount' => 150, 'currency' => 'USD', 'reason' => 'إنجاز مشروع التكامل', 'source_type' => 'manual', 'source_id' => 77])
            ->assertCreated()->json('data.0');
        $this->postJson('/api/missions/bonuses', ['type_id' => $type, 'employee_ids' => [$samer->id], 'amount' => 100, 'currency' => 'USD', 'reason' => 'تكرار', 'source_type' => 'manual', 'source_id' => 77])
            ->assertUnprocessable()->assertJsonPath('code', 'duplicate_source');
        // مقدّم الطلب لا يعتمده — مسؤول موارد بشرية آخر
        $this->login('nour.hassan@uniqueness.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'bonus');
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $l = PayLine::allCompanies()->where('source_type', 'bonus')->where('source_id', $b['id'])->firstOrFail();
        $this->assertSame(['E-220', 150.0, '2026-10'], [$l->element_code, $l->amount, $l->period]);
        // بعد يوم الإقفال (25) يُرحَّل للشهر التالي
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Damascus'));
        $this->assertSame('2026-11', $this->uq(fn () => \App\Services\Missions\PayLines::period()));
    }
}
