<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractCase;
use App\Models\Employee;
use App\Models\Notice;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 19 — العقود والتجديد وتقييم فترة التجربة (الجزء D: AC-CR-01..04) */
class ContractsTest extends TestCase
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

    private function uq(callable $fn): mixed { return Tenant::run(User::where('email', 'hr@uniqueness.sy')->value('company_id'), $fn); }
    private function emp(string $email): Employee { return Employee::allCompanies()->where('email', $email)->firstOrFail(); }
    private function sig(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** يجعل عقد الموظف ينتهي في تاريخ ثم يشغّل المهمة اليومية */
    private function endingOn(Employee $e, string $date): Contract
    {
        return $this->uq(function () use ($e, $date) {
            $c = Contract::where('employee_id', $e->id)->where('status', 'active')->firstOrFail();
            ContractCase::where('contract_id', $c->id)->delete();
            $c->update(['ends_on' => $date]);
            return $c->refresh();
        });
    }

    public function test_renewal_opens_manager_evaluates_hr_reviews_renews_and_new_contract_is_signed_then_active(): void
    {
        $samer = $this->emp('samer.halabi@uniqueness.sy');
        $c = $this->endingOn($samer, '2026-11-05');
        // AC-CR-01: الفتح الآلي قبل 30 يوماً
        $this->artisan('contracts:sweep')->assertSuccessful();
        $k = ContractCase::allCompanies()->where('contract_id', $c->id)->where('kind', 'renewal')->firstOrFail();
        $this->assertSame(['manager', '2026-10-19'], [$k->status, $k->manager_due->toDateString()]);
        $this->assertTrue(Notice::allCompanies()->where('link', 'contract-eval')->exists());

        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/me/contracts/cases/{$k->id}/evaluate", ['rating' => 'very_good', 'recommendation' => 'renew', 'notes' => 'أداء ممتاز'])->assertForbidden();   // لا يقيّم نفسه

        $this->login('alaa.sheikhani@uniqueness.sy');
        $team = $this->getJson('/api/me/contracts')->assertOk()->json('team');
        $this->assertSame($k->id, $team[0]['id']);
        $this->postJson("/api/me/contracts/cases/{$k->id}/evaluate", ['rating' => 'very_good', 'recommendation' => 'renew', 'notes' => 'قصير'])->assertUnprocessable();
        $this->postJson("/api/me/contracts/cases/{$k->id}/evaluate", ['rating' => 'very_good', 'recommendation' => 'renew', 'notes' => 'أداء ثابت وتعاون جيد مع الفريق'])->assertOk();

        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/hr/contracts/cases/{$k->id}/review", ['approve' => true, 'checks' => ['complete' => true]])->assertUnprocessable();
        $row = $this->postJson("/api/hr/contracts/cases/{$k->id}/review", ['approve' => true, 'checks' => ['complete' => true, 'signatures' => true, 'policy' => true]])->assertOk()->json('data');
        $this->assertSame(['decision', 'renew', 'FT-D', 12], [$row['status'], $row['suggestion']['decision'], $row['suggestion']['type_code'], $row['suggestion']['months']]);
        $row = $this->postJson("/api/hr/contracts/cases/{$k->id}/decide", ['decision' => 'renew'])->assertOk()->json('data');
        $this->assertSame(['preparing', 'renewed'], [$row['status'], $row['outcome']]);
        $new = Contract::allCompanies()->findOrFail($row['new_contract_id']);
        $this->assertSame(['pending_signatures', '2026-11-06', '2027-11-05', $c->id], [$new->status, $new->starts_on->toDateString(), $new->ends_on->toDateString(), $new->previous_id]);
        // BR-CR-08: لا تفعيل قبل التواقيع
        $this->postJson("/api/hr/contracts/{$new->id}/sign", ['role' => 'executive'])->assertForbidden();
        $this->postJson("/api/hr/contracts/{$new->id}/sign", ['role' => 'hr_director'])->assertOk();
        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/me/contracts/{$new->id}/sign", ['signature' => 'x'])->assertUnprocessable();
        $this->postJson("/api/me/contracts/{$new->id}/sign", ['signature' => $this->sig()])->assertOk();
        $this->login('mazen.droubi@uniqueness.sy');
        $this->postJson("/api/hr/contracts/{$new->id}/sign", ['role' => 'executive'])->assertOk()->assertJsonPath('data.status', 'signed');
        $this->assertSame('active', $c->fresh()->status);

        // AC-CR-03: عند حلول تاريخ البدء يسري الجديد ويؤرشف السابق «مجدَّد» وتُغلق الحالة
        Carbon::setTestNow(Carbon::parse('2026-11-06 07:00:00', 'Asia/Damascus'));
        $this->artisan('contracts:sweep')->assertSuccessful();
        $this->assertSame(['active', 'renewed', 'closed'], [$new->fresh()->status, $c->fresh()->status, $k->fresh()->status]);
        $this->login('hr@uniqueness.sy');
        $this->getJson("/api/hr/contracts/print/letter/{$k->id}")->assertOk()->assertJsonPath('which', 'renewed');
        $this->getJson("/api/hr/contracts/print/contract/{$new->id}")->assertOk()->assertJsonCount(3, 'signatures');
    }

    public function test_exception_goes_to_the_ceo_and_not_renewing_opens_an_exit(): void
    {
        $e = $this->emp('samer.halabi@uniqueness.sy');
        $c = $this->endingOn($e, '2026-11-01');
        $this->artisan('contracts:sweep');
        $k = ContractCase::allCompanies()->where('contract_id', $c->id)->firstOrFail();
        $this->login('alaa.sheikhani@uniqueness.sy');
        $this->postJson("/api/me/contracts/cases/{$k->id}/evaluate", ['rating' => 'good', 'recommendation' => 'renew', 'notes' => 'يحتاج متابعة لكنه ملتزم'])->assertOk();
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/hr/contracts/cases/{$k->id}/review", ['approve' => true, 'checks' => ['complete' => true, 'signatures' => true, 'policy' => true]])->assertOk();
        // يخالف الاقتراح (تجديد) ← مبرر إلزامي ثم موافقة الرئيس التنفيذي
        $this->postJson("/api/hr/contracts/cases/{$k->id}/decide", ['decision' => 'not_renew'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/hr/contracts/cases/{$k->id}/decide", ['decision' => 'not_renew', 'reason' => 'إعادة هيكلة القسم وإلغاء الوظيفة'])->assertOk()->assertJsonPath('data.status', 'exception');

        $this->login('hani.samman@uniqueness.sy');
        $row = collect($this->getJson('/api/me/approvals')->assertOk()->json('data'))->firstWhere('kind', 'contract_exception');
        $this->assertNotNull($row);
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $k->refresh();
        $this->assertSame(['closed', 'not_renewed'], [$k->status, $k->outcome]);
        $r = Resignation::allCompanies()->findOrFail($k->resignation_id);
        $this->assertSame(['termination', 'contract_end', '2026-11-01'], [$r->kind, $r->reason_key, $r->last_day->toDateString()]);
    }

    public function test_probation_evaluation_twenty_items_signatures_and_confirmation(): void
    {
        $e = $this->emp('samer.halabi@uniqueness.sy');
        $c = $this->uq(function () use ($e) {
            $e->update(['status' => 'probation']);
            $c = Contract::where('employee_id', $e->id)->firstOrFail();
            $c->update(['probation_ends_on' => '2026-10-20', 'probation_months' => 3]);
            return $c;
        });
        $this->artisan('contracts:sweep');
        $k = ContractCase::allCompanies()->where('contract_id', $c->id)->where('kind', 'probation')->firstOrFail();
        $items = collect(\App\Support\Settings::DEFAULTS['contracts']['items'])->pluck('key');

        $this->login('alaa.sheikhani@uniqueness.sy');
        $all = $items->mapWithKeys(fn ($k) => [$k => 'very_good'])->all();
        $this->postJson("/api/me/contracts/cases/{$k->id}/evaluate", ['items' => $all, 'recommendation' => 'confirm'])->assertUnprocessable();   // التبرير مطلوب لغير «جيد»
        $just = $items->mapWithKeys(fn ($k) => [$k => 'ملتزم'])->all();
        $this->postJson("/api/me/contracts/cases/{$k->id}/evaluate", ['items' => $all, 'justify' => $just, 'recommendation' => 'confirm'])->assertOk();
        $k->refresh();
        $this->assertSame([80, 'very_good', 'signatures'], [$k->evaluation['total'], $k->evaluation['grade'], $k->status]);

        $this->login('samer.halabi@uniqueness.sy');
        $this->assertCount(1, $this->getJson('/api/me/contracts')->json('evaluations'));
        $this->postJson("/api/me/contracts/cases/{$k->id}/sign", ['note' => 'اطلعت'])->assertOk();
        $this->assertSame('hr_review', $k->fresh()->status);

        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/hr/contracts/cases/{$k->id}/review", ['approve' => true, 'checks' => ['complete' => true, 'signatures' => true, 'policy' => true]])->assertOk()->assertJsonPath('data.suggestion.decision', 'confirm');
        $this->postJson("/api/hr/contracts/cases/{$k->id}/decide", ['decision' => 'extend', 'months' => 9, 'reason' => 'نحتاج وقتاً أطول'])->assertUnprocessable();
        $this->postJson("/api/hr/contracts/cases/{$k->id}/decide", ['decision' => 'confirm'])->assertOk()->assertJsonPath('data.outcome', 'confirmed');
        $this->assertSame('active', $e->fresh()->status);
        $this->getJson("/api/hr/contracts/print/letter/{$k->id}")->assertOk()->assertJsonPath('which', 'confirmed');
    }

    public function test_lapsed_contract_overlap_manual_contract_annex_and_settings(): void
    {
        $e = $this->emp('samer.halabi@uniqueness.sy');
        $c = $this->endingOn($e, '2026-10-10');
        $this->artisan('contracts:sweep');
        $this->assertSame('expired_no_action', $c->fresh()->status);   // BR-CR-09
        $this->assertTrue(Notice::allCompanies()->where('text_ar', 'like', '%منتهٍ دون إجراء%')->exists());

        $this->login('hr@uniqueness.sy');
        $type = $this->uq(fn () => \App\Services\Codes\Codes::resolve('contract_types', 'FT-D')->id);
        $other = $this->emp('nour.hassan@uniqueness.sy');
        $live = $this->uq(fn () => Contract::where('employee_id', $other->id)->where('status', 'active')->firstOrFail());
        // BR-CR-10: لا تداخل
        $this->postJson('/api/hr/contracts', ['employee_id' => $other->id, 'contract_type_id' => $type, 'starts_on' => $live->starts_on->toDateString()])->assertUnprocessable()->assertJsonPath('code', 'contract_overlap');
        $id = $this->postJson('/api/hr/contracts', ['employee_id' => $other->id, 'contract_type_id' => $type, 'starts_on' => $live->ends_on->copy()->addDay()->toDateString(), 'months' => 6])
            ->assertCreated()->assertJsonPath('data.status', 'pending_signatures')->json('data.id');
        $this->postJson("/api/hr/contracts/{$id}/cancel", ['reason' => 'خطأ في الإدخال'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        // ملحق: تعديل الدرجة
        $grade = $this->uq(fn () => \App\Services\Codes\Codes::resolve('grades', 'G05')->id);
        $this->postJson("/api/hr/contracts/{$live->id}/annexes", ['kind' => 'grade', 'grade_id' => $grade, 'effective_on' => '2026-10-12', 'text' => 'ترفيع إلى درجة مشرف'])->assertCreated()->assertJsonPath('status', 'applied');
        $this->assertSame($grade, $other->fresh()->grade_id);
        // الرادار والإعدادات
        $this->getJson('/api/hr/contracts')->assertOk()->assertJsonStructure(['radar' => ['windows', 'rows']]);
        $s = $this->getJson('/api/hr/contracts/settings')->assertOk()->json('settings');
        $this->putJson('/api/hr/contracts/settings', array_merge($s, ['renewal_open_days' => 45, 'fixed_type' => 'XX-X']))->assertUnprocessable();
        $this->putJson('/api/hr/contracts/settings', array_merge($s, ['renewal_open_days' => 45]))->assertOk()->assertJsonPath('settings.renewal_open_days', 45);
        // الموظف لا يرى سجل العقود في لوحة الموارد البشرية
        $this->login('samer.halabi@uniqueness.sy');
        $this->getJson('/api/hr/contracts')->assertForbidden();
    }
}
