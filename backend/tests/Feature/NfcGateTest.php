<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Mission;
use App\Models\NfEvent;
use App\Models\Notice;
use App\Models\Scan;
use App\Services\Attendance\NfcGate;
use App\Services\Attendance\WorkSchedules;
use App\Services\Codes\Codes;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 21: NFC وتعدد الفروع — التفعيل، التوقيع، العزل بين الشركات، صلاحية الفرع، المهمة، الانقطاع، الفقد، الملصق، المراقبة */
class NfcGateTest extends TestCase
{
    use RefreshDatabase;

    private Employee $samer;
    private int $dam; private int $alp;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        WorkSchedules::forget();
        $this->samer = Employee::where('email', 'samer.halabi@uniqueness.sy')->first();
        $this->dam = Location::withoutGlobalScopes()->where('code', 'dam')->value('id'); $this->alp = Location::withoutGlobalScopes()->where('code', 'alp')->value('id');
        $this->samer->update(['location_id' => $this->dam]);              // الفرع الأساسي: دمشق
        WorkSchedules::forget();
        $this->postJson('/api/auth/login', ['email' => 'hr@uniqueness.sy', 'password' => '123456'])->assertOk();
        $this->putJson("/api/hr/employees/{$this->samer->id}/card", ['uid' => '04:A1:B2:C3:D4'])->assertOk();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    /** جهاز في فرع، مفعّل برمز لمرة واحدة ← [المعرّف، السر] */
    private function device(int $loc, array $extra = []): array
    {
        $r = $this->postJson('/api/hr/devices', ['name_ar' => 'قارئ', 'name_en' => 'Reader', 'kind' => 'gateway', 'location_id' => $loc] + $extra)->assertCreated();
        $id = $r->json('data.id');
        $code = $this->postJson("/api/hr/devices/{$id}/activation")->assertOk()->json('code');
        $a = $this->postJson('/api/nf/activate', ['code' => $code, 'serial' => 'SN-1', 'lan_ip' => '192.168.1.201'])->assertOk();
        $this->postJson('/api/nf/activate', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'activation_invalid');   // لمرة واحدة
        return [$a->json('device_id'), $a->json('secret'), $id];
    }

    private function send(array $dev, array $events)
    {
        $body = json_encode(['events' => $events]);
        return $this->call('POST', '/api/nf/events', [], [], [], ['HTTP_X_DEVICE_ID' => $dev[0], 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $dev[1]), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    public function test_activation_signature_isolation_and_offline_batch(): void
    {
        $dev = $this->device($this->dam);
        $this->assertMatchesRegularExpression('/^DV-DAM-\d{2}$/', $dev[0]);
        $this->assertSame('192.168.1.201', Device::find($dev[2])->lan_ip);

        // توقيع خاطئ ← رفض الرسالة كلياً
        $body = json_encode(['events' => [['card' => '04A1B2C3D4', 'seq' => 1]]]);
        $this->call('POST', '/api/nf/events', [], [], [], ['HTTP_X_DEVICE_ID' => $dev[0], 'HTTP_X_SIGNATURE' => str_repeat('a', 64), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(401)->assertJsonPath('code', 'device_unknown');
        $this->assertSame(0, NfEvent::count());

        // AC-NF-03: 3 أحداث مخزّنة أثناء الانقطاع تصل بغير ترتيب، ثم يُعاد إرسالها ← لا تكرار
        $events = [['card' => '04A1B2C3D4', 'at' => '2026-10-12T14:05:00Z', 'seq' => 12], ['card' => '04A1B2C3D4', 'at' => '2026-10-12T05:02:00Z', 'seq' => 10],
            ['card' => 'FFEE0011', 'at' => '2026-10-12T05:03:00Z', 'seq' => 11]];
        $r = $this->send($dev, $events)->assertOk();
        $by = collect($r->json('results'))->keyBy('seq');
        $this->assertSame('accepted', $by[10]['decision']); $this->assertSame('home', $by[10]['reason']); $this->assertSame('in', $by[10]['direction']);
        $this->assertSame('accepted', $by[12]['decision']);
        $this->assertSame('rejected', $by[11]['decision']); $this->assertSame('unknown_card', $by[11]['reason']); $this->assertNull($by[11]['name']);
        $day = AttendanceDay::where('employee_id', $this->samer->id)->whereDate('date', '2026-10-12')->first();
        $this->assertSame('08:02', $day->in_at->format('H:i')); $this->assertSame('17:05', $day->out_at->format('H:i'));
        $this->assertSame($this->dam, $day->location_id);
        $this->send($dev, $events)->assertOk();
        $this->assertSame(3, NfEvent::count());
        $this->assertSame(2, Scan::where('employee_id', $this->samer->id)->count());

        // AC-NF-01: بطاقة مسجّلة في شركة أخرى فقط ← «غير معروفة» دون اسم ولا حضور في أي من الشركتين
        $other = $this->makeCompany('acme');
        $dept = \App\Models\Department::create(['code' => 'OPS', 'name_ar' => 'العمليات', 'name_en' => 'Ops']);
        $x = Employee::create(['department_id' => $dept->id, 'location_id' => $this->dam, 'code' => 'ACM-1', 'email' => 'v@acme.sy', 'name' => 'زائر', 'name_en' => 'Visitor', 'status' => 'active', 'hire_date' => '2026-01-01', 'gender' => 'm', 'title_ar' => 'م', 'title_en' => 'E']);
        NfcGate::issue($x, 'nfc', '04:99:88:77:66', null);
        Tenant::forget();
        $r = $this->send($dev, [['card' => '0499887766', 'at' => '2026-10-12T06:00:00Z', 'seq' => 20]])->assertOk();
        $this->assertSame('unknown_card', $r->json('results.0.reason'));
        $this->assertNull($r->json('results.0.name'));
        Tenant::run($other, fn () => $this->assertSame(0, AttendanceDay::where('employee_id', $x->id)->count()));
    }

    public function test_branch_access_mission_and_review(): void
    {
        $dev = $this->device($this->alp);                                            // جهاز في حلب
        // خارج الفرع ← قبول مع تعليم (الافتراضي)، ورفض المراجعة يحذف الحركة
        $r = $this->send($dev, [['card' => '04A1B2C3D4', 'at' => '2026-10-12T05:10:00Z', 'seq' => 1]])->assertOk();
        $this->assertSame('flagged', $r->json('results.0.decision'));
        $ev = NfEvent::where('seq', 1)->first();
        $this->assertSame('pending', $ev->review);
        $this->assertTrue(Notice::where('link', 'nfc:off_branch')->exists());
        $this->assertSame(1, $this->getJson('/api/hr/nfc/events?decision=pending')->assertOk()->json('total'));
        $this->postJson("/api/hr/nfc/events/{$ev->id}/review", ['accept' => false])->assertStatus(422);
        $this->postJson("/api/hr/nfc/events/{$ev->id}/review", ['accept' => false, 'note' => 'ليس في حلب اليوم'])->assertOk()->assertJsonPath('data.review', 'rejected');
        $this->assertSame(0, Scan::where('employee_id', $this->samer->id)->count());
        $this->assertNull(AttendanceDay::where('employee_id', $this->samer->id)->whereDate('date', '2026-10-12')->first());

        // السياسة «رفض» ← مرفوض
        $cfg = $this->getJson('/api/hr/nfc/settings')->assertOk()->json('data');
        $this->putJson('/api/hr/nfc/settings', ['off_branch' => 'reject'] + $cfg)->assertOk();
        $this->assertSame('off_branch', $this->send($dev, [['card' => '04A1B2C3D4', 'at' => '2026-10-12T05:20:00Z', 'seq' => 2]])->json('results.0.reason'));

        // محاكاة القرار بعد صلاحية مؤقتة لحلب
        $this->postJson("/api/hr/employees/{$this->samer->id}/branches", ['location_id' => $this->alp, 'kind' => 'temporary'])->assertStatus(422);
        $this->postJson("/api/hr/employees/{$this->samer->id}/branches", ['location_id' => $this->alp, 'kind' => 'temporary', 'from' => '2026-10-12', 'to' => '2026-10-13'])->assertCreated();
        $s = $this->postJson('/api/hr/nfc/simulate', ['device_id' => $dev[2], 'card' => '04:A1:B2:C3:D4'])->assertOk();
        $this->assertSame(['accepted', 'temporary'], [$s->json('data.decision'), $s->json('data.reason')]);
        $this->assertSame(2, NfEvent::count());   // المحاكاة لا تحفظ
        $acc = $this->getJson("/api/hr/employees/{$this->samer->id}/access")->assertOk();
        $this->assertCount(1, $acc->json('branches'));
        \App\Models\BranchAccess::query()->delete();

        // AC-NF-02: مهمة معتمدة إلى حلب اليوم ← قبول مرتبط برقم المهمة
        Tenant::run($this->samer->company_id, function () {
            Mission::create(['ref_no' => 'MS-26-DAM-00099', 'type_id' => Codes::resolve('mission_types', 'MT-30')->id, 'title' => 'زيارة فرع حلب', 'employee_id' => $this->samer->id,
                'status' => 'assigned', 'travel' => ['scope' => 'local', 'country' => 'SY', 'governorate' => 'ALP', 'city' => 'حلب', 'depart_on' => '2026-10-12', 'return_on' => '2026-10-13', 'transport' => 'road', 'km' => 0, 'legs' => []]]);
        });
        $r = $this->send($dev, [['card' => '04A1B2C3D4', 'at' => '2026-10-12T05:30:00Z', 'seq' => 3]])->assertOk();
        $this->assertSame(['accepted', 'mission'], [$r->json('results.0.decision'), $r->json('results.0.reason')]);
        $mid = Mission::allCompanies()->where('ref_no', 'MS-26-DAM-00099')->value('id');
        $this->assertSame($mid, NfEvent::where('seq', 3)->value('mission_id'));
        $this->assertSame($mid, AttendanceDay::where('employee_id', $this->samer->id)->whereDate('date', '2026-10-12')->value('mission_id'));
        $rep = $this->getJson('/api/hr/nfc/report?from=2026-10-01&to=2026-10-12')->assertOk();
        $this->assertSame(2, $rep->json('data.rejected.off_branch'));
        $this->assertNotEmpty($rep->json('data.off_branch'));
    }

    public function test_lost_card_exit_ip_list_sticker_and_monitor(): void
    {
        $dev = $this->device($this->dam);
        $cred = Credential::allCompanies()->where('employee_id', $this->samer->id)->where('status', 'active')->first();
        $this->postJson("/api/hr/credentials/{$cred->id}/status", ['status' => 'lost'])->assertStatus(422);
        $this->postJson("/api/hr/credentials/{$cred->id}/status", ['status' => 'lost', 'reason' => 'ضاعت في الطريق'])->assertOk()->assertJsonPath('data.status', 'lost');
        $this->assertNull($this->samer->fresh()->nfc_uid);
        $r = $this->send($dev, [['card' => '04A1B2C3D4', 'at' => '2026-10-12T05:00:00Z', 'seq' => 1]]);
        $this->assertSame('lost_card', $r->json('results.0.reason'));
        $this->assertTrue(Notice::where('link', 'nfc:blocked')->exists());
        // بطاقة بديلة تعمل فوراً، والقديمة تبقى مرفوضة
        $this->putJson("/api/hr/employees/{$this->samer->id}/card", ['uid' => '04:11:22:33:44'])->assertOk();
        $this->assertSame('accepted', $this->send($dev, [['card' => '0411223344', 'at' => '2026-10-12T05:05:00Z', 'seq' => 2]])->json('results.0.decision'));

        // قائمة عناوين الفرع ← عنوان آخر يُرفض كلياً
        Location::withoutGlobalScopes()->whereKey($this->dam)->update(['allowed_ips' => json_encode(['10.0.0.0/8'])]);
        $this->send($dev, [['card' => '0411223344', 'seq' => 3]])->assertStatus(403)->assertJsonPath('code', 'ip_not_allowed');
        Location::withoutGlobalScopes()->whereKey($this->dam)->update(['allowed_ips' => json_encode(['127.0.0.1'])]);
        $this->send($dev, [['card' => '0411223344', 'at' => '2026-10-12T06:00:00Z', 'seq' => 4]])->assertOk();

        // ملصق الفرع: خارج النطاق ثم داخله
        Location::withoutGlobalScopes()->whereKey($this->dam)->update(['lat' => 33.5138, 'lng' => 36.2765, 'radius' => 200]);
        $url = $this->getJson("/api/hr/branches/{$this->dam}/sticker")->assertStatus(404);   // مدينة مشتركة لا ملصق لها
        $mine = Location::create(['company_id' => $this->samer->company_id, 'code' => 'DAM01', 'name_ar' => 'فرع المزة', 'name_en' => 'Mazzeh', 'timezone' => 'Asia/Damascus', 'governorate' => 'DAM', 'lat' => 33.5138, 'lng' => 36.2765, 'radius' => 200]);
        $this->samer->update(['location_id' => $mine->id]); WorkSchedules::forget();
        $token = basename($this->postJson("/api/hr/branches/{$mine->id}/sticker")->assertOk()->json('url'));
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'samer.halabi@uniqueness.sy', 'password' => '123456'])->assertOk();
        $this->getJson("/api/me/checkin/branch/{$token}")->assertOk()->assertJsonPath('geo', true);
        $this->postJson("/api/me/checkin/branch/{$token}")->assertStatus(422)->assertJsonPath('code', 'location_required');
        $this->postJson("/api/me/checkin/branch/{$token}", ['lat' => 33.60, 'lng' => 36.30])->assertStatus(422)->assertJsonPath('code', 'out_of_range');
        Carbon::setTestNow(now()->addMinutes(5));
        $this->postJson("/api/me/checkin/branch/{$token}", ['lat' => 33.5139, 'lng' => 36.2766])->assertOk()->assertJsonPath('data.decision', 'accepted');
        $this->postJson('/api/me/checkin/branch/wrongtoken', ['lat' => 1, 'lng' => 1])->assertNotFound();

        // مراقبة الأجهزة: انقطاع النبضة ← تنبيه واحد
        Carbon::setTestNow(now()->addMinutes(30));
        Tenant::run($this->samer->company_id, fn () => $this->assertGreaterThanOrEqual(1, NfcGate::monitor()));
        Tenant::run($this->samer->company_id, fn () => $this->assertSame(0, NfcGate::monitor()));
        $this->assertTrue(Notice::where('link', 'nfc:devices')->exists());

        // انتهاء الخدمة يعطّل الاعتمادات
        Tenant::run($this->samer->company_id, fn () => NfcGate::disableAll($this->samer, 'test'));
        $this->assertSame(0, Credential::allCompanies()->where('employee_id', $this->samer->id)->where('status', 'active')->count());
    }
}
