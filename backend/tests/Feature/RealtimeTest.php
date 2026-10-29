<?php

namespace Tests\Feature;

use App\Events\Changed;
use App\Events\NoticeCreated;
use App\Models\Department;
use App\Models\User;
use App\Support\Notify;
use App\Support\Realtime;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** 11c: البث اللحظي — حدث «uhr.changed» لقناة الشركة بعد كل كتابة، «uhr.notice» للجرس، وصلاحيات القنوات */
class RealtimeTest extends TestCase
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

    public function test_writes_broadcast_one_changed_event_per_request_without_data(): void
    {
        $this->login('admin@alfarada.sy');
        Event::fake([Changed::class, NoticeCreated::class]);
        $d = Department::allCompanies()->where('code', 'hr')->firstOrFail();
        $this->putJson("/api/hr/admin/departments/{$d->id}", ['code' => 'hr', 'ar' => 'الموارد البشرية', 'en' => 'Human Resources', 'headcount' => 9, 'color' => 'brand', 'active' => true, 'head_id' => null])->assertOk();
        Event::assertDispatchedTimes(Changed::class, 1);
        Event::assertDispatched(Changed::class, function (Changed $e) use ($d) {
            $ch = $e->broadcastOn()[0]->name;
            return $e->companyId === $d->company_id && $ch === "private-company.{$d->company_id}" && in_array('departments', $e->tables, true)
                && array_keys($e->broadcastWith()) === ['tables', 'by'] && $e->broadcastAs() === 'uhr.changed';
        });
        // القراءة لا تبث
        Event::fake([Changed::class]);
        $this->getJson('/api/hr/admin/departments')->assertOk();
        Event::assertNotDispatched(Changed::class);
        $this->assertSame([], Realtime::pending());
    }

    public function test_notices_go_to_the_user_or_the_company_channel(): void
    {
        $c = $this->useCompany();
        Event::fake([NoticeCreated::class, Changed::class]);
        $samer = User::where('email', 'samer.halabi@alfarada.sy')->first();
        Notify::user($samer->id, 'اختبار', 'Test', 'requests');
        Notify::hr('للموارد البشرية', 'For HR', 'leaves');
        Event::assertDispatched(NoticeCreated::class, function ($e) use ($samer) { return $e->broadcastOn()[0]->name === "private-user.{$samer->id}" && $e->broadcastWith()['link'] === 'requests'; });
        Event::assertDispatched(NoticeCreated::class, fn ($e) => $e->broadcastOn()[0]->name === "private-company.{$c->id}" && $e->broadcastAs() === 'uhr.notice');
        Tenant::forget();
    }

    public function test_channel_rules_and_client_config(): void
    {
        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $company = $channels['company.{id}']; $user = $channels['user.{id}'];
        $hr = User::where('email', 'hr@alfarada.sy')->first();
        $samer = User::where('email', 'samer.halabi@alfarada.sy')->first();
        $other = User::where('email', 'hr@atmata.sy')->first();
        $this->assertTrue($company($hr, $hr->company_id));
        $this->assertFalse($company($samer, $samer->company_id));          // الموظف: قناته فقط
        $this->assertFalse($company($other, $hr->company_id));             // شركة أخرى
        $this->assertTrue($user($samer, $samer->id));
        $this->assertFalse($user($samer, $hr->id));

        $this->login('hr@alfarada.sy');
        $this->getJson('/api/realtime')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('channels', ["user.{$hr->id}", "company.{$hr->company_id}"]);
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/realtime')->assertOk()->assertJsonPath('channels', ["user.{$samer->id}"]);
    }
}
