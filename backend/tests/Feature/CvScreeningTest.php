<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateReview;
use App\Services\Ai\AiClient;
use App\Support\Settings;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 11f: فرز السير — فلاتر، قراءة جماعية بدفعة، ترتيب النتائج، الحد الشهري، التقدّم والتصدير */
class CvScreeningTest extends TestCase
{
    use RefreshDatabase;

    public array $order = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $t = $this;
        $this->app->instance(AiClient::class, new class($t) implements AiClient {
            public function __construct(private $t) {}
            public function available(): bool { return true; }
            public function model(): string { return 'local-test'; }
            public function json(string $system, string $prompt, array $schema): array
            {
                $this->t->order[] = strtok($prompt, "\n");                     // سطر الشاغر أولاً
                return ['score' => 30 + crc32($prompt) % 70, 'summary' => 'ملخص.', 'strengths' => ['قوة'], 'gaps' => ['نقص']];
            }
        });
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    public function test_filters_batch_reading_ranking_and_progress(): void
    {
        $this->login('hr@uniqueness.sy');
        $d = $this->getJson('/api/hr/cv-screening?stage=all')->assertOk()->json();
        $this->assertTrue($d['status']['available']);
        $this->assertTrue($d['can']['run']);
        $this->assertNotEmpty($d['majors']);
        $all = count($d['data']);
        $this->assertSame($all, $d['total']);

        // فلتر الاختصاص: كل النتائج تحمل الاختصاص المختار
        $major = $d['majors'][0];
        $rows = $this->getJson('/api/hr/cv-screening?stage=all&major='.urlencode($major))->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) $this->assertStringContainsString($major, json_encode(Candidate::find($r['id'])->education, JSON_UNESCAPED_UNICODE));
        $this->assertCount(0, $this->getJson('/api/hr/cv-screening?stage=all&read=done')->json('data'));

        // قراءة جماعية: مرتبة حسب الشاغر، ثم الأعلى قراءةً أولاً
        $ids = array_column($d['data'], 'id');
        $b = $this->postJson('/api/hr/cv-screening/run', ['ids' => $ids])->assertCreated()->json('batch');
        $this->assertSame([$all, $all, 0, 0, 0], [$b['total'], $b['done'], $b['left'], $b['counts']['already'], $b['eta_seconds']]);
        $this->assertCount($all, $this->groupedOrder());
        $ranked = $this->getJson('/api/hr/cv-screening?stage=all')->json('data');
        $scores = array_map(fn ($r) => $r['review']['score'], $ranked);
        $sorted = $scores; rsort($sorted);
        $this->assertSame($sorted, $scores);
        $this->assertSame(1, $ranked[0]['rank']);
        $this->assertNotNull(CandidateReview::allCompanies()->where('candidate_id', $ids[0])->value('seconds'));
        $this->assertSame($all, count($this->getJson('/api/hr/cv-screening?stage=all&read=done')->json('data')));
        $this->assertSame($b['id'], $this->getJson('/api/hr/cv-screening')->json('batch.id'));

        // المقروء يُتخطى إلا مع «إعادة القراءة»
        $again = $this->postJson('/api/hr/cv-screening/run', ['ids' => $ids])->assertCreated()->json('batch');
        $this->assertSame([0, $all], [$again['counts']['queued'], $again['counts']['already']]);
        $this->assertSame($all, $this->postJson('/api/hr/cv-screening/run', ['ids' => $ids, 'force' => true])->json('batch.counts.queued'));
        $this->getJson('/api/hr/cv-screening/export?stage=all')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cv.batch_review']);
    }

    /** الشواغر لا تتداخل: كل شاغر قراءاته متتالية */
    private function groupedOrder(): array
    {
        $seen = []; $prev = null;
        foreach ($this->order as $line) { if ($line !== $prev) { $this->assertNotContains($line, $seen, 'job blocks must be contiguous'); $seen[] = $line; } $prev = $line; }
        return $this->order;
    }

    public function test_monthly_limit_permissions_and_unavailable(): void
    {
        $this->login('hr@uniqueness.sy');
        $ids = array_column($this->getJson('/api/hr/cv-screening?stage=all')->json('data'), 'id');
        $cid = Candidate::find($ids[0])->company_id;
        Settings::putPlatform('ai_cv', ['enabled' => true, 'monthly_limit' => 2, 'companies' => []]);
        $b = $this->postJson('/api/hr/cv-screening/run', ['ids' => array_slice($ids, 0, 4)])->assertCreated()->json('batch');
        $this->assertSame([2, 2], [$b['counts']['queued'], $b['counts']['limit']]);
        $this->postJson('/api/hr/cv-screening/run', ['ids' => []])->assertStatus(422);
        Settings::putPlatform('ai_cv', ['enabled' => true, 'monthly_limit' => 300, 'companies' => [(string) $cid => ['enabled' => false]]]);
        $this->postJson('/api/hr/cv-screening/run', ['ids' => [$ids[5]]])->assertStatus(409)->assertJsonPath('code', 'review_unavailable');
        $this->login('samer.halabi@uniqueness.sy');
        $this->getJson('/api/hr/cv-screening')->assertForbidden();
        $this->postJson('/api/hr/cv-screening/run', ['ids' => [$ids[0]]])->assertForbidden();
    }
}
