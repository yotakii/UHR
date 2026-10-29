<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateReview;
use App\Services\Ai\AiClient;
use App\Services\Recruitment\CvReview;
use App\Support\Settings;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** قراءة السيرة بالنموذج المحلي: بالخلفية بعد التقديم، بلا هوية ولا بيانات اتصال، تحكم المنصة بالتشغيل والحد الشهري */
class CvReviewTest extends TestCase
{
    use RefreshDatabase;

    public array $prompts = [];
    public bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Storage::fake('local');
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $t = $this;
        $this->app->instance(AiClient::class, new class($t) implements AiClient {
            public function __construct(private $t) {}
            public function available(): bool { return true; }
            public function model(): string { return 'local-test'; }
            public function json(string $system, string $prompt, array $schema): array
            {
                if ($this->t->fail) throw new \RuntimeException('model down');
                $this->t->prompts[] = $prompt;
                return ['score' => 82, 'summary' => 'خبرة محاسبية مناسبة للشاغر.', 'strengths' => ['خبرة 4 سنوات في المحاسبة', 'إجادة جيدة للإنكليزية', ''], 'gaps' => ['لا خبرة في التدقيق']];
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

    private function apply(array $over = []): Candidate
    {
        $this->postJson('/api/auth/logout');
        $form = array_replace([
            'name' => 'سارة محمود الأحمد', 'name_en' => 'Sara Al-Ahmad', 'gender' => 'F', 'marital' => 'single', 'nationality' => 'سورية', 'dob' => '1997-03-14', 'pob' => 'دمشق',
            'national_id' => '01020304050', 'blood' => 'O+', 'mobile' => '+963 944 555 123', 'email' => 'sara.ahmad@mail.sy', 'address' => 'دمشق — المزة', 'linkedin' => 'linkedin.com/in/sara',
            'degree' => 2, 'institute' => 'جامعة دمشق', 'major' => 'محاسبة', 'grad_year' => 2019, 'grade' => 'جيد جداً', 'english' => 'good', 'years_exp' => 4,
            'experience' => [['company' => 'بنك سورية الدولي', 'position' => 'محاسب', 'from' => 2020, 'to' => 2026, 'reason' => 'تطور مهني']],
            'ref_name' => 'م. سليم حداد', 'ref_job' => 'مدير سابق', 'ref_phone' => '+963 944 120 330', 'shifts' => 0, 'travel' => 1, 'public_sector' => 0, 'conviction' => 0, 'salary' => 750,
            'availability' => '2026-11-01', 'source' => 'website', 'cv' => UploadedFile::fake()->create('Sara_CV.pdf', 240, 'application/pdf'),
            'password' => 'Sara2026pw', 'password_confirmation' => 'Sara2026pw', 'agree_info' => 1, 'agree_data' => 1, 'agree_medical' => 1, 'keep_cv' => 1,
        ], $over);
        $ref = $this->withHeaders(['Accept' => 'application/json'])->post('/api/public/jobs/alfarada/REQ-2026-034/apply', $form)->assertCreated()->json('data.ref');
        return Candidate::allCompanies()->where('ref', $ref)->firstOrFail();
    }

    public function test_application_is_read_in_the_background_without_personal_data(): void
    {
        $c = $this->apply();
        $r = CandidateReview::allCompanies()->where('candidate_id', $c->id)->firstOrFail();
        $this->assertSame(['done', 82, 'strong', 'local-test'], [$r->status, $r->score, $r->fit, $r->model]);
        $this->assertSame(['خبرة 4 سنوات في المحاسبة', 'إجادة جيدة للإنكليزية'], $r->strengths);   // الفارغ يُحذف
        $p = end($this->prompts);
        $this->assertStringContainsString('محاسب', $p);
        foreach (['سارة', 'Sara', 'sara.ahmad@mail.sy', '944 555', '01020304050', 'المزة', '1997', 'الجنسية'] as $secret) $this->assertStringNotContainsString($secret, $p);

        $this->login('hr@alfarada.sy');
        $f = $this->getJson("/api/hr/candidates/{$c->id}")->assertOk();
        $this->assertSame(82, $f->json('data.review_detail.score'));
        $this->assertSame('لا خبرة في التدقيق', $f->json('data.review_detail.gaps.0'));
        $this->assertTrue($f->json('data.review_available'));
        $card = collect($this->getJson('/api/hr/candidates')->json('data'))->firstWhere('id', $c->id);
        $this->assertSame(['status' => 'done', 'score' => 82, 'fit' => 'strong'], $card['review']);

        // إعادة القراءة
        $this->postJson("/api/hr/candidates/{$c->id}/review")->assertOk()->assertJsonPath('data.review_detail.status', 'done');
        $this->assertCount(2, $this->prompts);
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson("/api/hr/candidates/{$c->id}/review")->assertForbidden();
    }

    public function test_scrub_removes_identity_and_contacts_from_cv_text(): void
    {
        $c = new Candidate(['name' => 'سارة محمود الأحمد', 'name_en' => 'Sara Ahmad', 'email' => 'sara@mail.sy', 'mobile' => '+963 944 555 123', 'national_id' => '01020304050']);
        $out = app(CvReview::class)->scrub($c, "سارة الأحمد — محاسبة\nsara@mail.sy · +963 944 555 123 · رقم 01020304050 · https://site.sy/me\nخبرة 4 سنوات في المحاسبة");
        foreach (['سارة', 'الأحمد', 'sara@', '944', '0102030', 'https'] as $x) $this->assertStringNotContainsString($x, $out);
        $this->assertStringContainsString('خبرة 4 سنوات في المحاسبة', $out);
    }

    public function test_platform_controls_switch_and_monthly_limit(): void
    {
        $c0 = $this->apply();
        $this->login('platform@atmata.sy');
        $d = $this->getJson('/api/platform/cv-reading')->assertOk()->json('data');
        $alf = collect($d['companies'])->firstWhere('en', 'Uniqueness Development & Investment Co.');
        $this->assertSame(1, $alf['used']);
        $this->putJson('/api/platform/cv-reading', ['enabled' => true, 'monthly_limit' => 300, 'companies' => [['id' => $alf['id'], 'enabled' => false, 'monthly_limit' => null]]])->assertOk()
            ->assertJsonPath('data.companies.'.collect($d['companies'])->search(fn ($x) => $x['id'] === $alf['id']).'.enabled', false);

        $c1 = $this->apply(['email' => 'second@mail.sy', 'national_id' => '0999']);
        $this->assertSame(0, CandidateReview::allCompanies()->where('candidate_id', $c1->id)->count());   // موقوفة للشركة

        Settings::putPlatform('ai_cv', ['enabled' => true, 'monthly_limit' => 1, 'companies' => []]);
        $c2 = $this->apply(['email' => 'third@mail.sy', 'national_id' => '0888']);
        $this->assertSame(0, CandidateReview::allCompanies()->where('candidate_id', $c2->id)->count());   // بلغ الحد
        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/candidates/{$c2->id}/review")->assertStatus(409)->assertJsonPath('code', 'review_limit');
        $this->postJson("/api/hr/candidates/{$c0->id}/review")->assertOk();   // إعادة قراءة موجودة لا تحسب من الحد
        $this->getJson('/api/platform/cv-reading')->assertForbidden();
    }

    public function test_failure_is_recorded_and_never_blocks(): void
    {
        $c = $this->apply();
        $r = CandidateReview::allCompanies()->where('candidate_id', $c->id)->firstOrFail();
        $this->fail = true;
        try { \App\Support\Tenant::run($c->company_id, fn () => app(CvReview::class)->run($r)); } catch (\RuntimeException) {}
        $this->assertSame(['failed', 'model down'], [$r->refresh()->status, $r->error]);
    }
}
