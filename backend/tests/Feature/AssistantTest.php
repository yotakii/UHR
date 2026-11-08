<?php

namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Ai\Writer;
use App\Services\Assistant\Dates;
use App\Services\Assistant\Knowledge;
use App\Models\User;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 13 «المساعد»: أجوبة البيانات، الإجراءات بمعاينة وتأكيد بخطوتين، السياسات مع الملخص بالتدفق، العزل والحدود والإعدادات */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        $this->writer(false);
        $this->withHeader('Accept-Language', 'ar');   // عميل الاختبار يرسل الإنكليزية افتراضياً
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function writer(bool $up, ?string $out = null, bool $fail = false): void
    {
        $this->app->instance(Writer::class, new class($up, $out, $fail) implements Writer {
            public function __construct(private bool $up, private ?string $out, private bool $fail) {}
            public function available(): bool { return $this->up; }
            public function stream(string $system, string $prompt, callable $onDelta, array $options = []): string
            {
                if ($this->fail) throw new \RuntimeException('down');
                foreach (explode(' ', (string) $this->out) as $w) $onDelta($w.' ');
                return (string) $this->out;
            }
        });
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    /** @return array{events: array, final: array} */
    private function ask(string $text): array
    {
        $res = $this->postJson('/api/assistant/ask', ['text' => $text]);
        $res->assertOk();
        $events = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", $res->streamedContent()))));
        $done = collect($events)->firstWhere('t', 'done');
        $this->assertNotNull($done, 'no done event: '.json_encode($events, JSON_UNESCAPED_UNICODE));
        return ['events' => $events, 'final' => $done['message']];
    }

    public function test_date_parser_and_data_answers_never_show_salary(): void
    {
        $sun = Carbon::parse('2026-11-08');
        $this->assertSame(['2026-11-15'], Dates::parse('بدي إجازة من الأحد القادم لمدة يومين', $sun)['dates']);
        $this->assertSame(2, Dates::parse('بدي إجازة من الأحد القادم لمدة يومين', $sun)['days']);
        $this->assertSame(['2026-11-12', '2026-11-14'], Dates::parse('من 12/11 إلى 14/11', $sun)['dates']);
        $this->assertSame(['2026-11-20'], Dates::parse('20 تشرين الثاني', $sun)['dates']);
        $this->assertSame(2, Dates::parse('مغادرة ساعية بكرا ساعتين', $sun)['hours']);

        $this->login('samer.halabi@alfarada.sy');
        $idx = $this->getJson('/api/assistant')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('summaries', false)->json();
        $this->assertNotEmpty($idx['suggestions']);

        $b = $this->ask('كم رصيد إجازاتي؟')['final'];
        $this->assertSame('balance', $b['intent']);
        $this->assertSame('stats', $b['cards'][0]['type']);
        $this->assertStringContainsString('رصيدك السنوي', $b['text']);

        $samer = Employee::allCompanies()->where('email', 'samer.halabi@alfarada.sy')->first();
        $s = $this->ask('كم راتبي هذا الشهر؟')['final'];
        $this->assertSame('salary', $s['intent']);
        $this->assertStringNotContainsString((string) (int) $samer->salary, json_encode($s, JSON_UNESCAPED_UNICODE));   // ملاحظة 82: لا مبالغ في المحادثة

        $this->assertSame('holidays', $this->ask('متى العطلة الرسمية القادمة؟')['final']['intent']);
        $this->assertSame('requests', $this->ask('ما حالة طلباتي؟')['final']['intent']);
        $this->assertSame('attendance', $this->ask('حضوري هذا الشهر')['final']['intent']);
        $this->assertSame('attendance', $this->ask('من الغائبون اليوم؟')['final']['intent']);   // الموظف لا يرى حضور غيره
        $this->assertSame(2 * 6, AssistantMessage::allCompanies()->where('user_id', User::where('email', 'samer.halabi@alfarada.sy')->value('id'))->count());

        $this->login('hr@alfarada.sy');
        $h = $this->ask('من الغائبون اليوم؟')['final'];
        $this->assertSame('hr_today', $h['intent']);
        $this->assertSame('/hr/attendance', collect($h['cards'])->firstWhere('type', 'link')['to']);
        $this->assertSame('/hr/my-requests', collect($this->ask('كم رصيد إجازاتي')['final']['cards'])->firstWhere('type', 'link')['to']);
    }

    public function test_leave_action_needs_preview_and_two_step_confirmation(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $m = $this->ask('أريد إجازة سنوية من 2026-11-15 لمدة يومين بسبب سفر عائلي إلى حلب')['final'];
        $this->assertSame('leave', $m['intent']);
        $a = $m['action'];
        $this->assertSame('pending', $a['status']);
        $this->assertSame(['annual', '2026-11-15', '2026-11-16', 'سفر عائلي إلى حلب'], [$a['fields']['type'], $a['fields']['from'], $a['fields']['to'], $a['fields']['reason']]);
        $this->assertArrayHasKey('substitute_id', $a['errors']);   // البديل إلزامي
        $sub = $a['info']['substitutes'][0]['id'];

        // تعديل ← معاينة جديدة؛ التأكيد ببصمة قديمة لا يرسل
        $p = $this->postJson("/api/assistant/messages/{$m['id']}/action", ['decision' => 'confirm', 'fields' => ['substitute_id' => $sub] + $a['fields'], 'digest' => $a['digest']])->assertOk()->json('message.action');
        $this->assertSame('pending', $p['status']);
        $this->assertSame([], $p['errors']);
        $this->assertSame(0, LeaveRequest::allCompanies()->where('reason', 'سفر عائلي إلى حلب')->count());
        $done = $this->postJson("/api/assistant/messages/{$m['id']}/action", ['decision' => 'confirm', 'fields' => $p['fields'], 'digest' => $p['digest']])->assertOk()->json('message.action');
        $this->assertSame('done', $done['status']);
        $this->assertSame(1, LeaveRequest::allCompanies()->where('reason', 'سفر عائلي إلى حلب')->where('substitute_id', $sub)->count());
        $this->postJson("/api/assistant/messages/{$m['id']}/action", ['decision' => 'confirm', 'fields' => $p['fields'], 'digest' => $p['digest']])->assertStatus(409)->assertJsonPath('code', 'action_closed');

        // ساعية + إلغاء
        $h = $this->ask('بدي مغادرة ساعية يوم 2026-11-20 ساعتين')['final']['action'];
        $this->assertSame(['hourly', 2], [$h['fields']['type'], $h['fields']['hours']]);
        $id = AssistantMessage::allCompanies()->latest('id')->value('id');
        $this->postJson("/api/assistant/messages/{$id}/action", ['decision' => 'cancel'])->assertOk()->assertJsonPath('message.action.status', 'cancelled');

        // لا يتصرف أحد آخر بالبطاقة
        $this->login('dana.shami@alfarada.sy');
        $this->postJson("/api/assistant/messages/{$m['id']}/action", ['decision' => 'cancel'])->assertNotFound();
        $this->login('hr@atmata.sy');
        $this->postJson("/api/assistant/messages/{$m['id']}/action", ['decision' => 'cancel'])->assertNotFound();
    }

    public function test_policy_answers_stream_a_summary_and_fall_back(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $hits = app(Knowledge::class)->search(User::where('email', 'samer.halabi@alfarada.sy')->first(), 'ما سياسة الإجازة المرضية؟');
        $this->assertNotEmpty($hits);

        $r = $this->ask('ما سياسة الإجازة المرضية؟')['final'];
        $this->assertSame('policy', $r['intent']);
        $this->assertNotEmpty($r['sources']);
        $this->assertArrayNotHasKey('excerpt_full', $r['sources'][0]);
        $this->assertNull($r['summary']);   // الكاتب غير متاح ← الفقرات وحدها

        $this->writer(true, 'تُمنح الإجازة المرضية بتقرير طبي [1].');
        $x = $this->ask('ما سياسة الإجازة المرضية؟');
        $this->assertSame('reply', $x['events'][0]['t']);
        $this->assertGreaterThan(1, collect($x['events'])->where('t', 'delta')->count());
        $this->assertSame('done', $x['final']['summary']);
        $this->assertSame('تُمنح الإجازة المرضية بتقرير طبي [1].', $x['final']['text']);

        $this->writer(true, null, true);
        $f = $this->ask('ما سياسة الإجازة المرضية؟')['final'];
        $this->assertSame('failed', $f['summary']);
        $this->assertStringContainsString('أقرب الفقرات', $f['text']);

        $u = $this->ask('ما لون سيارة المدير العام المفضلة؟')['final'];
        $this->assertSame('unknown', $u['intent']);
        $this->assertNotEmpty($u['suggestions']);
    }

    public function test_settings_limit_disable_and_actions_toggle(): void
    {
        $this->login('hr@alfarada.sy');
        $cfg = $this->getJson('/api/assistant/settings')->assertOk()->json('data');
        $this->putJson('/api/assistant/settings', ['daily_limit' => 2, 'actions' => ['leave' => false] + $cfg['actions']] + $cfg)->assertOk()->assertJsonPath('data.daily_limit', 2);
        $this->login('samer.halabi@alfarada.sy');
        $l = $this->ask('أريد إجازة سنوية من 2026-11-15')['final'];
        $this->assertNull($l['action']);
        $this->assertStringContainsString('غير متاح', $l['text']);
        $this->ask('كم رصيد إجازاتي');
        $this->postJson('/api/assistant/ask', ['text' => 'مرحبا'])->assertStatus(429)->assertJsonPath('code', 'assistant_limit');
        $this->putJson('/api/assistant/settings', $cfg)->assertForbidden();
        $this->deleteJson('/api/assistant')->assertOk();
        $this->assertSame([], $this->getJson('/api/assistant')->json('history'));

        $this->login('hr@alfarada.sy');
        $this->putJson('/api/assistant/settings', ['enabled' => false] + $cfg)->assertOk();
        $this->postJson('/api/assistant/ask', ['text' => 'مرحبا'])->assertStatus(409)->assertJsonPath('code', 'assistant_off');
    }
}
