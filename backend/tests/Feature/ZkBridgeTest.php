<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Services\Attendance\WorkSchedules;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** المهمة 17: بصّامة ZKTeco عبر جسر الحاسوب — الإعداد، قائمة الموظفين، الحركات، التكرار، الأرقام غير المربوطة */
class ZkBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        WorkSchedules::forget();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_bridge_config_punches_and_mapping(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'hr@uniqueness.sy', 'password' => '123456'])->assertOk();
        $this->postJson('/api/hr/devices', ['name_ar' => 'بصّامة المدخل', 'name_en' => 'Entrance', 'kind' => 'zk', 'zk' => ['ip' => 'bad', 'port' => 4370, 'machine_no' => 1, 'card_order' => 'normal']])
            ->assertStatus(422)->assertJsonValidationErrors(['zk.ip']);
        $r = $this->postJson('/api/hr/devices', ['name_ar' => 'بصّامة المدخل', 'name_en' => 'Entrance', 'kind' => 'zk',
            'zk' => ['ip' => '172.16.1.188', 'port' => 4370, 'comm_key' => '0', 'machine_no' => 1, 'card_order' => 'normal']])->assertCreated();
        $id = $r->json('data.id'); $key = $r->json('key');
        $this->assertSame('172.16.1.188', $r->json('data.zk.ip'));

        // الجسر: الإعداد + الموظفون (الرقم من الرقم الوظيفي)
        $h = ['X-Device-Key' => $key];
        $cfg = $this->withHeaders($h)->getJson('/api/device/zk/config')->assertOk();
        $this->assertSame(4370, $cfg->json('zk.port'));
        $samer = Employee::where('email', 'samer.halabi@uniqueness.sy')->first();
        $this->assertSame('1007', $samer->fresh()->device_pin);
        $this->assertTrue(collect($cfg->json('users'))->contains(fn ($u) => $u['pin'] === '1007' && $u['name'] !== ''));
        $this->assertNull($cfg->json('since'));

        // حركات بغير ترتيب: الخروج قبل الدخول، وتكرار ضمن مهلة الجهاز، ورقم غير مربوط
        $p = $this->withHeaders($h)->postJson('/api/device/zk/punches', ['punches' => [
            ['pin' => '1007', 'time' => '2026-10-11 16:10:00', 'verify' => 1],
            ['pin' => '1007', 'time' => '2026-10-11 08:20:00', 'verify' => 1],
            ['pin' => '1007', 'time' => '2026-10-11 08:20:04', 'verify' => 1],
            ['pin' => '9999', 'time' => '2026-10-11 08:30:00', 'verify' => 2],
        ], 'info' => ['serial' => 'ABC123']])->assertOk();
        $this->assertSame(['received' => 4, 'new' => 4, 'applied' => 2, 'dup' => 1, 'unmatched' => 1], $p->json());
        $day = AttendanceDay::where('employee_id', $samer->id)->where('date', '2026-10-11')->first();
        $this->assertSame('08:20', $day->in_at->format('H:i'));
        $this->assertSame('16:10', $day->out_at->format('H:i'));
        $this->assertSame('device', $day->source);

        // إعادة الإرسال نفسه لا تكرر شيئاً
        $this->withHeaders($h)->postJson('/api/device/zk/punches', ['punches' => [['pin' => '1007', 'time' => '2026-10-11 16:10:00']]])->assertOk()->assertJsonPath('new', 0);
        $this->assertNotNull($this->withHeaders($h)->getJson('/api/device/zk/config')->json('since'));

        // لوحة الجهاز: الرقم غير المربوط ← ربطه بموظف يطبّق حركاته
        $ov = $this->getJson("/api/hr/devices/{$id}/zk")->assertOk();
        $this->assertSame('9999', $ov->json('unmatched.0.pin'));
        $other = Employee::where('email', 'alaa.sheikhani@uniqueness.sy')->first();
        $this->postJson('/api/hr/devices/zk/map', ['pin' => '1007', 'employee_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'pin_taken');
        $this->postJson('/api/hr/devices/zk/map', ['pin' => '9999', 'employee_id' => $other->id])->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame(0, DevicePunch::where('status', 'unmatched')->count());
        $this->assertSame('08:30', AttendanceDay::where('employee_id', $other->id)->where('date', '2026-10-11')->first()->in_at->format('H:i'));

        // حالة الاتصال بالبصّامة + جهاز ليس بصّامة يُرفض
        $this->withHeaders($h)->postJson('/api/device/zk/status', ['ok' => false, 'error' => 'connect failed'])->assertOk();
        $this->assertSame('connect failed', $this->getJson('/api/hr/devices')->json('data.0.last_sync.error') ?? collect($this->getJson('/api/hr/devices')->json('data'))->firstWhere('id', $id)['last_sync']['error']);
        $nfc = $this->postJson('/api/hr/devices', ['name_ar' => 'قارئ', 'name_en' => 'Reader', 'kind' => 'reader'])->assertCreated();
        $this->withHeaders(['X-Device-Key' => $nfc->json('key')])->getJson('/api/device/zk/config')->assertStatus(409);

        // الحزمة
        $this->post("/api/hr/devices/{$id}/zk-package")->assertOk()->assertHeader('content-type', 'application/zip');
    }
}
