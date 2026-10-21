<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\Policy;
use App\Models\Scan;
use App\Services\Attendance\DayCalc;
use App\Services\Onboarding\F19;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** M08 — الحضور: السجل اليومي والملخص، التصحيح، سياسات الدوام بتاريخ نفاذ، العطل، أجهزة الحضور والتمرير */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, string $password = '123456'): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    private function emp(string $code): Employee { return Employee::allCompanies()->where('code', $code)->firstOrFail(); }

    public function test_new_company_gets_the_company_policy_and_demo_data_keeps_the_demo_shift(): void
    {
        $this->makeCompany('newco');
        $this->assertSame(540, Settings::policy('shift')['start']);                 // 09:00
        $this->assertSame([6, 0, 1, 2, 3, 4], Settings::policy('work_days'));       // السبت–الخميس
        $this->assertTrue(DayCalc::weekend('2026-10-16'));                           // الجمعة
        $this->assertFalse(DayCalc::weekend('2026-10-17'));                          // السبت يوم عمل
        Tenant::set($this->emp('UHR-1001')->company);
        $this->assertSame(480, Settings::policy('shift')['start']);                 // بيانات الديمو 08:00
    }

    public function test_daily_log_period_summary_and_export(): void
    {
        $this->login('hr@alfarada.sy');
        $r = $this->getJson('/api/hr/attendance')->assertOk()->json();
        $this->assertSame('2026-10-12', $r['date']);
        $this->assertSame(count($r['rows']), collect($r['rows'])->count());
        $this->assertSame(collect($r['rows'])->whereNotNull('in')->count(), $r['counts']['present']);
        $this->assertGreaterThan(0, $r['counts']['present']);
        $this->assertTrue($r['can']['edit']);
        $this->getJson('/api/hr/attendance?date=2026-10-20')->assertUnprocessable();          // لا أيام مستقبلية

        $p = $this->getJson('/api/hr/attendance/period?from=2026-09-29&to=2026-10-12')->assertOk()->json();
        $samer = collect($p['rows'])->firstWhere('employee.code', 'UHR-1007');
        $this->assertGreaterThan(0, $samer['present']);
        $this->assertArrayHasKey('deduct', $samer);
        $this->get('/api/hr/attendance/export?from=2026-09-29&to=2026-10-12')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'attendance.export')->exists());

        $this->login('basel.atassi@alfarada.sy');                                            // المالية: عرض وتصدير، بلا تعديل
        $this->getJson('/api/hr/attendance')->assertOk()->assertJsonPath('can.edit', false);
        $this->putJson('/api/hr/attendance/'.$this->emp('UHR-1007')->id, ['date' => '2026-10-11', 'in' => '08:00', 'note' => 'نسي البطاقة'])->assertForbidden();
    }

    public function test_incomplete_day_and_manual_correction_with_reason(): void
    {
        $e = $this->emp('UHR-1007');
        Tenant::set($e->company);
        AttendanceDay::updateOrCreate(['employee_id' => $e->id, 'date' => '2026-10-11'], ['in_at' => Carbon::parse('2026-10-11 08:05:00', 'Asia/Damascus'), 'out_at' => null, 'status' => 'present']);
        $this->login('hr@alfarada.sy');
        $row = collect($this->getJson('/api/hr/attendance?date=2026-10-11')->json('rows'))->firstWhere('employee.id', $e->id);
        $this->assertSame('incomplete', $row['status']);                                   // دخول بلا خروج = ناقص

        $this->putJson("/api/hr/attendance/{$e->id}", ['date' => '2026-10-11', 'in' => '08:05', 'out' => '16:10'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->putJson("/api/hr/attendance/{$e->id}", ['date' => '2026-10-11', 'in' => '08:05', 'out' => '07:00', 'note' => 'نسي تسجيل الخروج'])->assertUnprocessable()->assertJsonPath('code', 'out_before_in');
        $d = $this->putJson("/api/hr/attendance/{$e->id}", ['date' => '2026-10-11', 'in' => '08:05', 'out' => '16:10', 'note' => 'نسي تسجيل الخروج'])->assertOk()->json('data');
        $this->assertSame(['present', '16:10', true], [$d['status'], $d['out'], $d['corrected']]);
        $a = AuditLog::where('action', 'attendance.corrected')->latest('id')->first();
        $this->assertSame(['in' => '08:05', 'out' => null], $a->before);
        $this->assertSame('نسي تسجيل الخروج', $a->after['note']);
        $this->assertStringContainsString('تصحيح دوام 2026-10-11', $e->logs()->reorder()->latest('id')->value('text_ar'));
    }

    public function test_policy_versions_validation_preview_and_department_override(): void
    {
        $this->login('hr@alfarada.sy');
        $p = $this->getJson('/api/hr/policies')->assertOk()->json();
        $this->assertSame('current', $p['versions']['shift'][0]['state']);
        $this->assertSame(480, $p['current']['shift']['start']);
        $shift = ['start' => 540, 'end' => 1020, 'grace' => 0, 'break' => 60, 'break_after' => 300, 'ot_threshold' => 30, 'night_from' => 1140];

        $this->postJson('/api/hr/policies', ['key' => 'shift', 'effective_from' => '2026-10-01', 'value' => $shift])->assertUnprocessable()->assertJsonPath('code', 'past_effective');
        $this->postJson('/api/hr/policies', ['key' => 'shift', 'effective_from' => '2026-11-01', 'value' => ['end' => 400] + $shift])->assertUnprocessable()->assertJsonValidationErrors('end');
        $this->postJson('/api/hr/policies', ['key' => 'nope', 'effective_from' => '2026-11-01', 'value' => $shift])->assertUnprocessable();

        // معاينة: البداية 09:00 بدل 08:00 تقلل أيام التأخير في آخر أسبوعين
        $pv = $this->postJson('/api/hr/policies/preview', ['key' => 'shift', 'value' => $shift])->assertOk()->json();
        $this->assertLessThan($pv['current']['late_days'], $pv['proposed']['late_days']);

        // نسخة مستقبلية (رمضان مثلاً) لا تغيّر اليوم، وتُحذف قبل نفاذها
        $v = $this->postJson('/api/hr/policies', ['key' => 'shift', 'effective_from' => '2026-11-01', 'value' => $shift])->assertOk()->json('versions.shift');
        $this->assertSame(['future', 'current'], array_column($v, 'state'));
        Tenant::set($this->emp('UHR-1001')->company);
        $this->assertSame(480, Settings::policy('shift')['start']);
        $this->assertSame(540, Settings::policy('shift', '2026-11-02')['start']);
        $this->assertTrue(AuditLog::where('action', 'policy.created')->exists());
        $this->deleteJson('/api/hr/policies/'.$v[0]['id'])->assertOk();
        $this->deleteJson('/api/hr/policies/'.$v[0]['id'] + 1)->assertNotFound();
        $cur = Policy::where('key', 'shift')->firstOrFail();
        $this->deleteJson("/api/hr/policies/{$cur->id}")->assertStatus(409)->assertJsonPath('code', 'policy_in_force');

        // نسخة نافذة اليوم لإدارة واحدة
        $it = $this->emp('UHR-1007')->department_id;
        $this->postJson('/api/hr/policies', ['key' => 'shift', 'department_id' => $it, 'effective_from' => '2026-10-12', 'value' => $shift])->assertOk();
        $this->postJson('/api/hr/policies', ['key' => 'late_deduction', 'department_id' => $it, 'effective_from' => '2026-10-12', 'value' => Settings::POLICY_DEFAULTS['late_deduction']])->assertUnprocessable()->assertJsonPath('code', 'policy_company_only');
        $this->assertSame(0, DayCalc::day('2026-10-12', '2026-10-12 08:40:00', null, $it)['late']);
        $this->assertSame(40, DayCalc::day('2026-10-12', '2026-10-12 08:40:00')['late']);

        // شرائح الحسم متتالية بلا فجوات
        $bad = Settings::POLICY_DEFAULTS['late_deduction']; $bad['tiers'][1]['from'] = 20;
        $this->postJson('/api/hr/policies', ['key' => 'late_deduction', 'effective_from' => '2026-10-12', 'value' => $bad])->assertUnprocessable()->assertJsonPath('code', 'tiers_order');

        $this->login('basel.atassi@alfarada.sy');                                            // المالية لا تعدّل السياسات
        $this->postJson('/api/hr/policies', ['key' => 'shift', 'effective_from' => '2026-11-01', 'value' => $shift])->assertForbidden();
    }

    public function test_holidays_count_as_rest_days(): void
    {
        $this->login('hr@alfarada.sy');
        $this->postJson('/api/hr/holidays/fixed', ['year' => 2026])->assertOk()->assertJsonCount(7, 'data');
        $this->postJson('/api/hr/holidays/fixed', ['year' => 2026])->assertOk()->assertJsonCount(7, 'data');     // بلا تكرار
        $r = $this->postJson('/api/hr/holidays', ['date' => '2026-03-30', 'days' => 3, 'name_ar' => 'عيد الفطر', 'name_en' => 'Eid al-Fitr'])->assertOk()->json('data');
        $this->assertCount(10, $r);
        $this->postJson('/api/hr/holidays', ['date' => '2026-10-06', 'name_ar' => 'x', 'name_en' => 'x'])->assertUnprocessable();
        Tenant::set($this->emp('UHR-1001')->company);
        $this->assertSame('holiday', DayCalc::day('2026-10-06')['status']);
        $this->deleteJson('/api/hr/holidays/'.$r[0]['id'])->assertOk()->assertJsonCount(9, 'data');
    }

    public function test_device_registration_scan_in_out_duplicate_unknown_and_revoke(): void
    {
        $this->login('hr@alfarada.sy');
        $res = $this->postJson('/api/hr/devices', ['name_ar' => 'بوابة المقر', 'name_en' => 'HQ gate', 'kind' => 'reader'])->assertCreated()->json();
        $key = $res['key'];
        $this->assertSame(48, strlen($key));
        $this->assertArrayNotHasKey('key', $this->getJson('/api/hr/devices')->json('data.0'));
        $this->postJson('/api/auth/logout');

        $h = ['X-Device-Key' => $key];
        $this->getJson('/api/device/me')->assertUnauthorized()->assertJsonPath('code', 'device_unknown');
        $this->getJson('/api/device/me', $h)->assertOk()->assertJsonPath('device.name.en', 'HQ gate')->assertJsonPath('company.slug', 'alfarada');

        // موظف جديد قيد المباشرة: أول تمرير يُنجز البند 15 من F-19
        $e = $this->emp('UHR-1007');
        Tenant::set($e->company);
        AttendanceDay::where('employee_id', $e->id)->where('date', '2026-10-12')->delete();
        Onboarding::updateOrCreate(['employee_id' => $e->id], ['checklist' => F19::blank(0)]);
        $dec = (string) hexdec(str_replace(':', '', $e->nfc_uid));                         // UHR-NFC-Bridge يرسل الرقم عشرياً
        $in = $this->postJson('/api/device/scan', ['uid' => $dec, 'via' => 'bridge'], $h)->assertOk()->json();
        $this->assertSame(['ok', 'in', 'UHR-1007', '10:30'], [$in['kind'], $in['type'], $in['employee']['code'], $in['time']]);
        $this->assertSame(150, $in['late']);
        $this->assertTrue(Onboarding::where('employee_id', $e->id)->first()->checklist[14]);
        $this->postJson('/api/device/scan', ['uid' => $e->nfc_uid], $h)->assertOk()->assertJsonPath('kind', 'dup');   // خلال مهلة التكرار

        Carbon::setTestNow(Carbon::parse('2026-10-12 17:05:00', 'Asia/Damascus'));
        $out = $this->postJson('/api/device/scan', ['uid' => strtolower($e->nfc_uid)], $h)->assertOk()->json();
        $this->assertSame(['out', '17:05', 1], [$out['type'], $out['time'], count(array_filter($out['feed'], fn ($f) => $f['type'] === 'out'))]);
        $day = AttendanceDay::where('employee_id', $e->id)->where('date', '2026-10-12')->first();
        $this->assertSame(['10:30', '17:05'], [$day->in_at->setTimezone('Asia/Damascus')->format('H:i'), $day->out_at->setTimezone('Asia/Damascus')->format('H:i')]);
        $this->assertSame(2, Scan::where('employee_id', $e->id)->whereDate('ts', '2026-10-12')->count());

        $this->postJson('/api/device/scan', ['uid' => 'DEADBEEF01'], $h)->assertOk()->assertJsonPath('kind', 'unknown');

        // بطاقة موظف شركة أخرى لا تُعرف على جهاز الفرادة
        $other = Employee::allCompanies()->where('company_id', '!=', $e->company_id)->whereNotNull('nfc_uid')->first();
        if ($other) $this->postJson('/api/device/scan', ['uid' => $other->nfc_uid], $h)->assertOk()->assertJsonPath('kind', 'unknown');

        // إيقاف الجهاز يقطعه فوراً، وتجديد المفتاح يبطل القديم
        $this->login('hr@alfarada.sy');
        $id = $res['data']['id'];
        $new = $this->postJson("/api/hr/devices/{$id}/rotate")->assertOk()->json('key');
        $this->postJson('/api/auth/logout');
        $this->getJson('/api/device/me', $h)->assertUnauthorized();
        $this->getJson('/api/device/me', ['X-Device-Key' => $new])->assertOk();
        Device::allCompanies()->find($id)->update(['active' => false]);
        $this->getJson('/api/device/me', ['X-Device-Key' => $new])->assertUnauthorized();
    }

    public function test_employee_file_shows_incomplete_and_uses_the_department_schedule(): void
    {
        $e = $this->emp('UHR-1007');
        Tenant::set($e->company);
        AttendanceDay::updateOrCreate(['employee_id' => $e->id, 'date' => '2026-10-11'], ['in_at' => Carbon::parse('2026-10-11 08:05:00', 'Asia/Damascus'), 'out_at' => null, 'status' => 'present']);
        $this->login('hr@alfarada.sy');
        $rows = $this->getJson("/api/hr/employees/{$e->id}")->json('data.attendance');
        $this->assertSame('incomplete', collect($rows)->firstWhere('date', '2026-10-11')['status']);
    }

    public function test_card_tapped_on_a_bridge_reaches_the_link_card_window(): void
    {
        $this->login('hr@alfarada.sy');
        $key = $this->postJson('/api/hr/devices', ['name_ar' => 'جسر', 'name_en' => 'Bridge', 'kind' => 'bridge'])->json('key');
        $e = $this->emp('UHR-1012');
        $now = $this->getJson("/api/hr/employees/{$e->id}/card/last-read")->assertOk()->json('now');
        $this->getJson("/api/hr/employees/{$e->id}/card/last-read?since=".urlencode($now))->assertOk()->assertJsonPath('data', null);
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:05', 'Asia/Damascus'));
        $this->postJson('/api/device/scan', ['uid' => 'A1B2C3D4E5', 'via' => 'bridge'], ['X-Device-Key' => $key])->assertOk()->assertJsonPath('kind', 'unknown');
        $r = $this->getJson("/api/hr/employees/{$e->id}/card/last-read?since=".urlencode($now))->assertOk()->json('data');
        $this->assertSame(['A1:B2:C3:D4:E5', false, 'جسر'], [$r['uid'], $r['known'], $r['device']['ar']]);
        $this->putJson("/api/hr/employees/{$e->id}/card", ['uid' => $r['uid']])->assertOk()->assertJsonPath('data.nfc_uid', 'A1:B2:C3:D4:E5');
        $this->login('basel.atassi@alfarada.sy');
        $this->getJson("/api/hr/employees/{$e->id}/card/last-read")->assertForbidden();
    }

    public function test_ready_made_bridge_package_carries_a_fresh_key_and_the_system_url(): void
    {
        $this->login('hr@alfarada.sy');
        $res = $this->postJson('/api/hr/devices', ['name_ar' => 'قارئ', 'name_en' => 'Reader', 'kind' => 'bridge'])->json();
        $r = $this->post("/api/hr/devices/{$res['data']['id']}/package", [], ['Origin' => 'http://localhost:5180'])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($path, file_get_contents($r->baseResponse->getFile()->getPathname()));
        $zip = new \ZipArchive(); $zip->open($path);
        foreach (['bridge.ps1', 'install.bat', 'uninstall.bat', 'run-visible.bat', 'uhr-bridge.ini'] as $f) $this->assertNotFalse($zip->locateName("UHR-NFC-Bridge/{$f}"), $f);
        $ini = $zip->getFromName('UHR-NFC-Bridge/uhr-bridge.ini'); $zip->close();
        $this->assertStringContainsString('URL=http://localhost:5180', $ini);
        preg_match('/KEY=(\S+)/', $ini, $m);
        $this->postJson('/api/auth/logout');
        $this->getJson('/api/device/me', ['X-Device-Key' => $res['key']])->assertUnauthorized();      // المفتاح القديم توقف
        $this->getJson('/api/device/me', ['X-Device-Key' => $m[1]])->assertOk();
        $this->login('hr@alfarada.sy');
        $this->assertTrue($this->getJson('/api/hr/devices')->json('data.0.online'));
    }
}
