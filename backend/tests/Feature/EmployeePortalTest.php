<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\Scan;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** M14 بوابة الموظف: الرئيسية، التسجيل من الجوال (السياسة والنطاق الجغرافي)، فريقي، ملفي الشخصي */
class EmployeePortalTest extends TestCase
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
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function terminal(Employee $e, array $over): void
    {
        Tenant::run($e->company_id, function () use ($over) {
            Policy::create(['key' => 'terminal', 'effective_from' => now()->toDateString(), 'value' => array_merge(Settings::policy('terminal'), $over)]);
            Settings::bumpPolicies();
        });
    }

    public function test_home_check_in_and_out_from_the_portal(): void
    {
        $samer = Employee::allCompanies()->where('code', 'UHR-1007')->firstOrFail();
        AttendanceDay::allCompanies()->where('employee_id', $samer->id)->where('date', '2026-10-12')->delete();
        $this->login('samer.halabi@alfarada.sy');
        $h = $this->getJson('/api/me/home')->assertOk()->json();
        $this->assertNull($h['today']['in']);
        $this->assertTrue($h['today']['checkin']);
        $this->assertNotNull($h['me']['manager']);
        $this->assertArrayHasKey('annual', $h['balance']);
        $this->assertLessThanOrEqual(8, count($h['team']['rows']));

        $r = $this->postJson('/api/me/checkin')->assertOk()->json();
        $this->assertSame('in', $r['result']['type']);
        $this->assertSame('10:30', $r['today']['in']);
        $this->assertGreaterThan(0, $r['today']['late']);
        $this->assertSame('portal', AttendanceDay::allCompanies()->where('employee_id', $samer->id)->where('date', '2026-10-12')->value('source'));
        $this->assertSame('phone', Scan::allCompanies()->where('employee_id', $samer->id)->latest('id')->value('via'));
        $this->postJson('/api/me/checkin')->assertConflict()->assertJsonPath('code', 'dup');
        Carbon::setTestNow(Carbon::parse('2026-10-12 16:30:00', 'Asia/Damascus'));
        $this->postJson('/api/me/checkin')->assertOk()->assertJsonPath('result.type', 'out')->assertJsonPath('today.checkin', false);
        $this->postJson('/api/me/checkin')->assertConflict()->assertJsonPath('code', 'checkin_closed');
    }

    public function test_policy_switch_and_geofence(): void
    {
        $samer = Employee::allCompanies()->where('code', 'UHR-1007')->firstOrFail();
        AttendanceDay::allCompanies()->where('employee_id', $samer->id)->where('date', '2026-10-12')->delete();
        $this->terminal($samer, ['portal_checkin' => false]);
        $this->login('samer.halabi@alfarada.sy');
        $this->assertFalse($this->getJson('/api/me/home')->json('today.checkin'));
        $this->postJson('/api/me/checkin')->assertForbidden()->assertJsonPath('code', 'portal_checkin_off');

        Policy::allCompanies()->where('key', 'terminal')->where('effective_from', '2026-10-12')->delete();
        $this->terminal($samer, ['portal_checkin' => true, 'geo_lat' => 33.5138, 'geo_lng' => 36.2765, 'geo_radius' => 300]);
        $this->assertSame(300, $this->getJson('/api/me/home')->json('geo.radius'));
        $this->postJson('/api/me/checkin')->assertUnprocessable()->assertJsonPath('code', 'location_required');
        $this->postJson('/api/me/checkin', ['lat' => 33.53, 'lng' => 36.30])->assertUnprocessable()->assertJsonPath('code', 'out_of_range');
        $this->postJson('/api/me/checkin', ['lat' => 33.5140, 'lng' => 36.2768])->assertOk()->assertJsonPath('result.type', 'in');
    }

    public function test_team_and_profile(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $t = $this->getJson('/api/me/team')->assertOk()->json();
        $this->assertTrue(collect($t['rows'])->contains('me', true));
        $this->assertTrue(collect($t['rows'])->every(fn ($r) => $r['phone'] === null || mb_strlen($r['phone']) <= 7));
        $p = $this->getJson('/api/me/my-file')->assertOk()->json();
        $this->assertSame('UHR-1007', $p['me']['code']);
        $this->assertNotEmpty($p['log']);
        $this->putJson('/api/me/my-file/phone', ['phone' => 'abc'])->assertUnprocessable();
        $this->putJson('/api/me/my-file/phone', ['phone' => '+963 944 111 222'])->assertOk()->assertJsonPath('me.phone', '+963 944 111 222');
        $this->login('leen.ali@mail.sy');
        $this->getJson('/api/me/home')->assertForbidden();
    }
}
