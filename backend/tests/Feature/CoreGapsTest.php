<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\External;
use App\Models\Location;
use App\Models\Mission;
use App\Models\MissionAmend;
use App\Models\MissionInvite;
use App\Models\Notice;
use App\Models\NfEvent;
use App\Models\User;
use App\Models\VolunteerLog;
use App\Services\Attendance\NfcGate;
use App\Services\Attendance\WorkSchedules;
use App\Services\Codes\CodeCatalog;
use App\Services\Codes\Codes;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 28: إكمال الأنظمة الأساسية — المهمات (السلسلة، المنطقة، الدعوة، التعديل، الإشارة)، NFC (الانحراف، رمز الذاكرة، الهاتف المسجّل، بطاقات غير الموظفين)، العقود، الترميز */
class CoreGapsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $samer;
    private int $cid;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        WorkSchedules::forget();
        $this->samer = Employee::where('email', 'samer.halabi@uniqueness.sy')->first();
        $this->cid = $this->samer->company_id;
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void { $this->postJson('/api/auth/logout'); $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk(); }
    private function uq(callable $fn): mixed { return Tenant::run($this->cid, $fn); }
    private function as(User $u): void { $this->postJson('/api/auth/logout'); $this->actingAs($u); }

    public function test_missions_zone_chain_by_type_invite_amend_and_mentions(): void
    {
        $this->uq(function () {
            $this->samer->update(['grade_id' => Codes::resolve('grades', 'G03')->id, 'location_id' => Location::withoutGlobalScopes()->where('code', 'dam')->value('id')]);
            \App\Models\ExchangeRate::create(['date' => '2026-10-01', 'from' => 'USD', 'to' => 'SYP', 'rate' => 10000]);
            Settings::put('missions', array_merge(\App\Services\Missions\Missions::cfg(), ['zones' => [['key' => 'NE', 'ar' => 'الشمال الشرقي', 'en' => 'North-east', 'governorates' => ['HAS'], 'countries' => [], 'factor' => 1.5]]]));
            // FR-TM-002/008: سلسلة خاصة بالنوع MT-30 (الموارد البشرية فقط)
            \App\Models\ApprovalChain::create(['type' => 'mission:MT-30', 'levels' => [\App\Services\Workflow\Workflow::level('hr_director')]]);
        });
        $this->login('samer.halabi@uniqueness.sy');
        $type = $this->uq(fn () => Codes::resolve('mission_types', 'MT-30')->id);
        $m = $this->postJson('/api/missions', ['type_id' => $type, 'title' => 'زيارة فرع الحسكة', 'travel' => ['scope' => 'local', 'governorate' => 'HAS', 'city' => 'الحسكة', 'purpose' => 'تدقيق', 'depart_on' => '2026-10-20', 'return_on' => '2026-10-22', 'transport' => 'road']])->assertCreated()->json('data');
        $line = collect($m['estimate']['lines'])->firstWhere('code', 'E-110');
        $this->assertSame('NE', $line['zone'] ?? null);   // FR-TM-009: معامل المنطقة على البدل
        $this->assertEquals(150000.0, (float) $line['rate']);   // 100000 × 1.5
        $this->postJson("/api/missions/{$m['id']}/submit")->assertOk();
        $steps = Mission::allCompanies()->find($m['id']);
        $this->assertSame(['hr_director'], \App\Models\RequestStep::where('subject_type', $steps->getMorphClass())->where('subject_id', $m['id'])->pluck('role_key')->all());

        $this->login('hr@uniqueness.sy');
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'mission');
        $this->postJson("/api/me/approvals/{$row['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->assertSame('assigned', Mission::allCompanies()->find($m['id'])->status);

        // FR-TM-024: التمديد يزيد الكلفة ← طلب تعديل لا يُطبَّق قبل الاعتماد (المدير ثم المالية)
        $this->postJson("/api/missions/{$m['id']}/amend", ['depart_on' => '2026-10-20', 'return_on' => '2026-10-25', 'reason' => 'تمديد لاستكمال التدقيق'])->assertOk();
        $this->assertSame('2026-10-22', Mission::allCompanies()->find($m['id'])->travel['return_on']);
        $a = MissionAmend::allCompanies()->first();
        $this->assertSame('pending_manager', $a->status);
        $mgr = User::where('employee_id', $this->samer->manager_id)->first();
        $fin = User::whereHas('roles', fn ($q) => $q->where('key', 'finance'))->where('company_id', $this->cid)->first();
        foreach ([$mgr, $fin] as $u) { $this->as($u); $r = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'mission_amend'); $this->assertNotNull($r); $this->postJson("/api/me/approvals/{$r['step_id']}", ['decision' => 'approve'])->assertOk(); }
        $this->assertSame('2026-10-25', Mission::allCompanies()->find($m['id'])->travel['return_on']);

        // FR-TM-026: دعوة عمل بموافقة مستقلة ← رقم دعوة
        $this->login('samer.halabi@uniqueness.sy');
        $this->postJson("/api/missions/{$m['id']}/invites", ['guest_name' => 'م. خالد يوسف', 'organization' => 'شركة الشرق', 'purpose' => 'مراجعة فنية للمعدات', 'from_on' => '2026-10-21', 'to_on' => '2026-10-22'])->assertOk();
        $this->login('hr@uniqueness.sy');
        $r = collect($this->getJson('/api/me/approvals')->json('data'))->firstWhere('kind', 'mission_invite');
        $this->postJson("/api/me/approvals/{$r['step_id']}", ['decision' => 'approve'])->assertOk();
        $this->assertMatchesRegularExpression('/^INV-2026-\d{4}$/', MissionInvite::allCompanies()->first()->letter_no);

        // FR-TM-006: الإشارة إلى مستخدم ← إشعار
        $this->postJson("/api/missions/{$m['id']}/comments", ['text' => 'راجع التقرير @[المدير]('.$mgr->id.')'])->assertOk();
        $this->assertTrue(Notice::where('user_id', $mgr->id)->where('link', 'mission:'.$m['id'])->exists());
    }

    public function test_nfc_drift_memory_token_phone_binding_and_external_card(): void
    {
        $this->login('hr@uniqueness.sy');
        $dam = Location::withoutGlobalScopes()->where('code', 'dam')->value('id');
        $this->samer->update(['location_id' => $dam]); WorkSchedules::forget();
        $this->putJson("/api/hr/employees/{$this->samer->id}/card", ['uid' => '04:A1:B2:C3:D4'])->assertOk();
        $id = $this->postJson('/api/hr/devices', ['name_ar' => 'قارئ', 'name_en' => 'Reader', 'kind' => 'gateway', 'location_id' => $dam])->assertCreated()->json('data.id');
        $code = $this->postJson("/api/hr/devices/{$id}/activation")->assertOk()->json('code');
        $a = $this->postJson('/api/nf/activate', ['code' => $code])->assertOk();
        $send = function (array $events) use ($a) { $b = json_encode(['events' => $events]); return $this->call('POST', '/api/nf/events', [], [], [], ['HTTP_X_DEVICE_ID' => $a->json('device_id'), 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $b, $a->json('secret')), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $b); };

        // FR-NF-016: ساعة الجهاز متقدمة 10 دقائق ← يُصحَّح وقت الحدث
        Device::allCompanies()->whereKey($id)->update(['clock_drift' => 600]);
        $send([['card' => '04A1B2C3D4', 'at' => '2026-10-12T05:10:00Z', 'seq' => 1]])->assertOk();
        $this->assertSame('2026-10-12 05:00:00', NfEvent::allCompanies()->where('seq', 1)->first()->occurred_at->utc()->format('Y-m-d H:i:s'));
        Device::allCompanies()->whereKey($id)->update(['clock_drift' => 0]);

        // FR-NF-018: رمز الذاكرة مطلوب حين يكون الأمان «ذاكرة»
        $cred = Credential::allCompanies()->where('employee_id', $this->samer->id)->where('type', 'nfc')->where('status', 'active')->first();
        $tok = $this->postJson("/api/hr/credentials/{$cred->id}/token")->assertOk()->json('token');
        $this->assertSame(15, strlen($tok));
        $this->uq(fn () => Settings::put('nfc', array_merge(NfcGate::cfg(), ['card_security' => 'memory'])));
        $r = $send([['card' => '04A1B2C3D4', 'at' => '2026-10-12T14:00:00Z', 'seq' => 2]])->assertOk();
        $this->assertSame('token_mismatch', $r->json('results.0.reason'));
        $r = $send([['card' => '04A1B2C3D4', 'at' => '2026-10-12T14:00:00Z', 'seq' => 3, 'token' => $tok]])->assertOk();
        $this->assertSame('accepted', $r->json('results.0.decision'));
        $this->uq(fn () => Settings::put('nfc', array_merge(NfcGate::cfg(), ['card_security' => 'uid', 'sticker_device' => 'bind_first', 'sticker_geo' => false])));

        // FR-NF-017: الهاتف المسجّل — أول جهاز يُربط، والجهاز الآخر يُرفض
        $sticker = basename($this->getJson("/api/hr/branches/{$dam}/sticker")->assertOk()->json('url'));
        $this->login('samer.halabi@uniqueness.sy');
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Damascus'));
        $this->postJson("/api/me/checkin/branch/{$sticker}", ['device_token' => str_repeat('a', 32)])->assertOk();
        $this->postJson("/api/me/checkin/branch/{$sticker}", ['device_token' => str_repeat('b', 32)])->assertStatus(403)->assertJsonPath('code', 'device_not_registered');

        // FR-VL-007/015: بطاقة متطوع ← نقرتان ← ساعات بانتظار المشرف، وتُعطَّل بانتهاء الاتفاقية
        $this->login('hr@uniqueness.sy');
        [$x, $g] = $this->uq(function () {
            $x = External::create(['ref' => 'EXT-2026-0099', 'kind' => 'volunteer', 'entity' => 'person', 'name' => 'متطوع', 'email' => 'v99@mail.sy', 'phone' => '0999', 'status' => 'active', 'source' => 'internal']);
            $g = Engagement::create(['ref' => 'ENG-2026-0099', 'external_id' => $x->id, 'title' => 'دعم', 'supervisor_id' => $this->samer->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'weekly_hours' => 10, 'relation' => 'independent', 'status' => 'active']);
            return [$x, $g];
        });
        $this->postJson("/api/hr/externals/{$x->id}/card", ['identifier' => '04:55:66:77:88'])->assertOk();
        $send([['card' => '0455667788', 'at' => '2026-10-13T06:00:00Z', 'seq' => 10], ['card' => '0455667788', 'at' => '2026-10-13T09:30:00Z', 'seq' => 11]])->assertOk();
        $log = VolunteerLog::allCompanies()->where('engagement_id', $g->id)->first();
        $this->assertSame([3.5, 'pending', 'nfc'], [(float) $log->hours, $log->status, $log->via]);
        $this->uq(fn () => NfcGate::disableExternal($x->id, 'انتهاء'));
        $this->assertSame('revoked', Credential::allCompanies()->where('external_id', $x->id)->value('status'));
    }

    public function test_contract_salary_annex_events_rest_days_codes_and_group(): void
    {
        $this->login('hr@uniqueness.sy');
        $this->unlockSalaries();
        // FR-CR-018 + FR-CR-014: ملحق راتب يغيّر الراتب ويُبلغ المالية
        $c = Contract::allCompanies()->where('employee_id', $this->samer->id)->where('status', 'active')->first();
        $this->postJson("/api/hr/contracts/{$c->id}/annexes", ['kind' => 'salary', 'effective_on' => '2026-10-12', 'text' => 'تعديل الراتب بعد التقييم السنوي', 'pay' => ['basic' => 900, 'housing' => 200]])->assertCreated();
        $this->assertSame(1100.0, (float) $this->samer->fresh()->salary);
        $fin = User::whereHas('roles', fn ($q) => $q->where('key', 'finance'))->where('company_id', $this->cid)->value('id');
        $this->assertTrue(Notice::where('user_id', $fin)->where('link', 'contracts')->exists());

        // FR-CS-018: عطلة الفرع الأسبوعية تحل محل أيام عمل السياسة
        $dam = Location::withoutGlobalScopes()->where('code', 'dam')->first();
        $this->uq(fn () => $dam->update(['rest_days' => [5, 6]]));
        $this->samer->update(['location_id' => $dam->id]); WorkSchedules::forget();
        $this->assertSame([0, 1, 2, 3, 4], $this->uq(fn () => WorkSchedules::resolve($this->samer->fresh(), '2026-10-12')['work_days']));

        // FR-CS-022: اقتراح الاسم الإنكليزي وكشف التكرار
        $s = $this->getJson('/api/cs/lists/jobs/suggest?name='.urlencode('محاسب'))->assertOk();
        $this->assertSame('Accountant', $s->json('en'));
        $this->assertNotEmpty($s->json('duplicates'));
        // FR-CS-019: سجل ترميز الوثائق
        $this->assertContains('TD14', array_column($this->getJson('/api/cs/documents')->assertOk()->json('data'), 'code'));
        // FR-CS-008: هيكل القطاع (إدارات + عناصر راتب)
        $this->uq(fn () => $this->assertGreaterThan(0, CodeCatalog::loadSectorStructure('factory')['depts']));

        // FR-CS-021: قائمة مجموعة تُورَّث للشركة التابعة (للقراءة فقط هناك)
        $child = $this->makeCompany('sub-co');
        $child->update(['parent_id' => $this->cid]); Tenant::forget();
        $this->postJson('/api/cs/lists/cost_centers/inherit', ['inherit' => true])->assertOk();
        $this->uq(fn () => Codes::store(Codes::list('cost_centers'), ['code' => 'CC-7777', 'ar' => 'مركز المجموعة', 'en' => 'Group centre']));
        $copy = Tenant::run($child, fn () => \App\Models\CodeItem::where('code', 'CC-7777')->first());
        $this->assertNotNull($copy); $this->assertNotNull($copy->group_item_id);
        Tenant::run($child, fn () => $this->assertThrows(fn () => Codes::store(Codes::list('cost_centers'), ['code' => 'CC-7777', 'ar' => 'تعديل', 'en' => 'Edit'], $copy), \App\Exceptions\ApiException::class));
    }
}
