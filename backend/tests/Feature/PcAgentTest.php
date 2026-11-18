<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Notice;
use App\Models\PcAgent;
use App\Models\User;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** وكيل جهاز الموظف UHR-Agent (D-15): الخروج بالبطاقة يقفل الجهاز، الطابور للجهاز المطفأ، الإلغاء بدخول جديد، والحالة للموارد البشرية */
class PcAgentTest extends TestCase
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
        $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function out(): void { $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards(); }

    public function test_checkout_locks_the_employee_computer_through_the_agent(): void
    {
        $e = Employee::allCompanies()->where('code', 'UHR-1007')->firstOrFail();
        Tenant::set($e->company);
        AttendanceDay::where('employee_id', $e->id)->where('date', '2026-10-12')->delete();

        $this->login('hr@uniqueness.sy');
        $dev = $this->postJson('/api/hr/devices', ['name_ar' => 'بوابة المقر', 'name_en' => 'HQ gate', 'kind' => 'reader'])->assertCreated()->json('key');
        $agent = $this->postJson('/api/hr/pc-agents', ['employee_id' => $e->id, 'name' => 'Laptop-Samer'])->assertCreated()->json('data');
        $this->assertFalse($agent['paired']);
        $zipRes = $this->post("/api/hr/pc-agents/{$agent['id']}/package")->assertOk();
        $zip = new \ZipArchive(); $zip->open($zipRes->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('UHR-Agent/uhr-agent.ps1'));
        $this->assertNotFalse($zip->locateName('UHR-Agent/install.bat'));
        preg_match('/KEY=(\S+)/', $zip->getFromName('UHR-Agent/uhr-agent.ini'), $m); $key = $m[1];
        $this->assertSame(48, strlen($key));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.package']);
        $this->out();

        $h = ['X-Agent-Key' => $key];
        $this->postJson('/api/agent/poll', [])->assertUnauthorized()->assertJsonPath('code', 'agent_unknown');
        $this->postJson('/api/agent/poll', ['host' => 'PC-07', 'user' => 'UNIQ\\samer', 'version' => '1.0.0'], $h)->assertOk()->assertJsonCount(0, 'commands')->assertJsonPath('poll', 10);

        // دخول بالبطاقة: لا أمر — خروج بالبطاقة: أمر تسجيل خروج بتحذير 60 ثانية + إشعار للموظف
        $d = ['X-Device-Key' => $dev];
        $this->postJson('/api/device/scan', ['uid' => $e->nfc_uid], $d)->assertOk()->assertJsonPath('type', 'in');
        $this->assertSame(0, AgentCommand::count());
        Carbon::setTestNow(Carbon::parse('2026-10-12 17:05:00', 'Asia/Damascus'));
        $this->postJson('/api/device/scan', ['uid' => $e->nfc_uid], $d)->assertOk()->assertJsonPath('type', 'out')->assertJsonPath('agents', 1);
        $uid = User::where('employee_id', $e->id)->value('id');
        $this->assertTrue(Notice::where('user_id', $uid)->where('text_ar', 'like', 'تم تسجيل خروجك%')->exists());

        $cmd = $this->postJson('/api/agent/poll', ['host' => 'PC-07'], $h)->assertOk()->json('commands.0');
        $this->assertSame(['logoff', 60, 'checkout'], [$cmd['action'], $cmd['warn'], $cmd['reason']]);
        $this->assertSame('sent', AgentCommand::find($cmd['id'])->status);
        $this->postJson("/api/agent/commands/{$cmd['id']}", ['status' => 'done'], $h)->assertOk()->assertJsonPath('status', 'done');
        $this->postJson('/api/agent/poll', [], $h)->assertJsonCount(0, 'commands');

        // الجهاز مطفأ: الأمر ينتظر في الطابور، ثم يُلغى بدخول جديد صباح اليوم التالي فلا يُقفل الجهاز
        Carbon::setTestNow(Carbon::parse('2026-10-12 17:30:00', 'Asia/Damascus'));
        $this->postJson('/api/device/scan', ['uid' => $e->nfc_uid], $d)->assertOk()->assertJsonPath('type', 'out');
        $queued = AgentCommand::where('status', 'queued')->firstOrFail();
        Carbon::setTestNow(Carbon::parse('2026-10-12 17:40:00', 'Asia/Damascus'));
        $this->artisan('agents:sweep')->assertSuccessful();
        $this->assertTrue(Notice::where('link', 'pc-agents')->where('text_ar', 'like', '%غير متصل%')->exists());
        Carbon::setTestNow(Carbon::parse('2026-10-13 08:55:00', 'Asia/Damascus'));
        $this->postJson('/api/device/scan', ['uid' => $e->nfc_uid], $d)->assertOk()->assertJsonPath('type', 'in');
        $this->assertSame('cancelled', $queued->fresh()->status);
        $this->postJson('/api/agent/poll', [], $h)->assertJsonCount(0, 'commands');

        // حركة بصّامة قديمة وصلت متأخرة لا تُقفل الجهاز، والجوال ليس مصدراً افتراضياً
        $this->assertSame(0, \App\Services\Attendance\PcAgents::afterTap($e, 'out', 'card', now()->subHour()));
        $this->assertSame(0, \App\Services\Attendance\PcAgents::afterTap($e, 'out', 'phone', now()));

        // أمر تجريبي من الموارد البشرية يفشل ← تنبيه
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/hr/pc-agents/{$agent['id']}/test", ['action' => 'lock'])->assertOk()->assertJsonPath('data.last.reason', 'test');
        $this->out();
        $t = $this->postJson('/api/agent/poll', [], $h)->json('commands.0');
        $this->assertSame('lock', $t['action']);
        $this->postJson("/api/agent/commands/{$t['id']}", ['status' => 'failed', 'error' => 'LockWorkStation error 5'], $h)->assertOk();
        $this->assertTrue(Notice::where('link', 'pc-agents')->where('text_ar', 'like', 'تعذّر تنفيذ%')->exists());

        // تسجيل الخروج يُبلَّغ «نُفّذ» قبل التنفيذ، ويصحَّح بـ«فشل» إن رفض ويندوز
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/hr/pc-agents/{$agent['id']}/test", ['action' => 'logoff'])->assertOk();
        $this->out();
        $l = $this->postJson('/api/agent/poll', [], $h)->json('commands.0');
        $this->postJson("/api/agent/commands/{$l['id']}", ['status' => 'done'], $h)->assertOk();
        $this->postJson("/api/agent/commands/{$l['id']}", ['status' => 'failed', 'error' => 'ExitWindowsEx error 1'], $h)->assertJsonPath('status', 'failed');

        // الموارد البشرية ترى الحالة؛ شركة أخرى لا ترى شيئاً؛ الإيقاف يقطع الوكيل
        $this->login('hr@uniqueness.sy');
        $row = $this->getJson('/api/hr/pc-agents')->assertOk()->json('data.0');
        $this->assertSame(['Laptop-Samer', true, true, 'PC-07', 'failed'], [$row['name'], $row['online'], $row['paired'], $row['host'], $row['last']['status']]);
        $this->assertCount(4, $this->getJson("/api/hr/pc-agents/{$agent['id']}/commands")->json('data'));
        $this->putJson('/api/hr/pc-agents/settings', ['enabled' => true, 'action' => 'reboot', 'warn_seconds' => 60, 'postpone_minutes' => 0, 'sources' => ['card'], 'fresh_minutes' => 10, 'queue_hours' => 12,
            'offline_minutes' => 3, 'poll_seconds' => 10, 'notify_employee' => true])->assertStatus(422);
        $this->putJson('/api/hr/pc-agents/settings', ['enabled' => true, 'action' => 'lock', 'warn_seconds' => 30, 'postpone_minutes' => 5, 'sources' => ['card', 'fingerprint'], 'fresh_minutes' => 10,
            'queue_hours' => 12, 'offline_minutes' => 3, 'poll_seconds' => 15, 'notify_employee' => false])->assertOk()->assertJsonPath('settings.action', 'lock');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.pc_agent']);
        $this->putJson("/api/hr/pc-agents/{$agent['id']}", ['name' => 'Laptop-Samer', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->login('hr@atmata.sy');
        $this->assertCount(0, $this->getJson('/api/hr/pc-agents')->assertOk()->json('data'));
        $this->postJson("/api/hr/pc-agents/{$agent['id']}/test", ['action' => 'lock'])->assertNotFound();
        $this->out();
        $this->postJson('/api/agent/poll', [], $h)->assertUnauthorized();
        $this->assertSame(1, PcAgent::allCompanies()->count());
        $this->assertSame('lock', Settings::merged('pc_agent')['action']);
    }
}
