<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobOpening;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** بوابة فرص العمل العامة متعددة الشركات (SRS §10.15) */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_portal_lists_jobs_of_every_publishing_company(): void
    {
        $p = $this->getJson('/api/public/portal')->assertOk()->assertJsonPath('hero.title.ar', 'فرص عمل حقيقية في شركات سورية رائدة')
            ->assertJsonPath('stats.jobs', 8)->assertJsonPath('stats.companies', 2);
        $this->assertSame(['alfarada', 'atmata'], collect($p->json('companies'))->pluck('slug')->all());
        $this->assertStringStartsWith('/api/public/companies/atmata/logo', collect($p->json('companies'))->firstWhere('slug', 'atmata')['logo']);

        $jobs = collect($this->getJson('/api/public/jobs')->assertOk()->json('data'));
        $this->assertCount(8, $jobs);   // 3 Atmata + 5 الفرادة المعتمدة (الأخيرة بانتظار المراجعة)
        $this->assertSame('ATM-2026-001', $jobs[0]['code']);   // الوظائف الحرجة أولاً
        $this->assertTrue($jobs[0]['hard_to_fill']);
        $this->assertFalse($jobs->contains('code', 'REQ-2026-041'));
        $this->assertArrayNotHasKey('salary_min', $jobs[0]);
        $this->assertSame('Atmata Group', $jobs[0]['company']['name']['en']);
    }

    public function test_filters(): void
    {
        $this->assertCount(3, $this->getJson('/api/public/jobs?company=atmata')->json('data'));
        $this->assertSame(['REQ-2026-036', 'ATM-2026-003'], collect($this->getJson('/api/public/jobs?city=alp')->json('data'))->pluck('code')->sort()->reverse()->values()->all());
        $this->assertSame(['REQ-2026-038'], collect($this->getJson('/api/public/jobs?type=shifts')->json('data'))->pluck('code')->all());
        $this->assertSame(['REQ-2026-038'], collect($this->getJson('/api/public/jobs?exp=0')->json('data'))->pluck('code')->all());
        $this->assertSame(['ATM-2026-001'], collect($this->getJson('/api/public/jobs?q=laravel')->json('data'))->pluck('code')->all());
        $this->assertCount(3, $this->getJson('/api/public/jobs?q='.urlencode('أتمتة'))->json('data'));   // بحث باسم الشركة
    }

    public function test_company_page_shows_its_jobs_including_pending_review(): void
    {
        $c = $this->getJson('/api/public/companies/alfarada')->assertOk()->assertJsonPath('data.brand_latin', 'UNIQUENESS GROUP')->assertJsonPath('data.about.en', 'Syrian development and investment group in Damascus, Aleppo and Homs.');
        $this->assertCount(6, $c->json('data.jobs'));   // صفحة الشركة لا تنتظر مراجعة المنصة
        $this->getJson('/api/public/jobs/alfarada/REQ-2026-041')->assertOk()->assertJsonPath('data.company.slug', 'alfarada')->assertJsonCount(3, 'data.requirements');
        $this->getJson('/api/public/companies/nope')->assertNotFound();
    }

    public function test_internal_closed_expired_or_suspended_jobs_disappear(): void
    {
        JobOpening::allCompanies()->where('code', 'ATM-2026-001')->update(['visibility' => 'internal']);
        JobOpening::allCompanies()->where('code', 'ATM-2026-002')->update(['closes_at' => '2026-10-11']);
        JobOpening::allCompanies()->where('code', 'REQ-2026-031')->update(['status' => 'closed']);
        JobOpening::allCompanies()->where('code', 'REQ-2026-034')->update(['visibility' => 'company']);   // صفحة الشركة فقط
        $codes = collect($this->getJson('/api/public/jobs')->json('data'))->pluck('code');
        $this->assertEqualsCanonicalizing(['ATM-2026-003', 'REQ-2026-036', 'REQ-2026-038', 'REQ-2026-040'], $codes->all());
        $this->getJson('/api/public/jobs/atmata/ATM-2026-001')->assertNotFound();
        $this->getJson('/api/public/jobs/alfarada/REQ-2026-034')->assertOk();

        Company::where('slug', 'alfarada')->update(['portal_enabled' => false]);
        $this->assertSame(['ATM-2026-003'], collect($this->getJson('/api/public/jobs')->json('data'))->pluck('code')->all());
        Company::where('slug', 'atmata')->update(['status' => 'suspended']);
        $this->assertSame([], $this->getJson('/api/public/jobs')->json('data'));
        $this->getJson('/api/public/companies/atmata')->assertNotFound();
    }

    public function test_expired_jobs_are_closed_by_the_scheduler(): void
    {
        JobOpening::allCompanies()->where('code', 'ATM-2026-002')->update(['closes_at' => '2026-10-11']);
        $this->artisan('jobs:close-expired')->expectsOutputToContain('Closed 1')->assertSuccessful();
        $this->assertSame('closed', JobOpening::allCompanies()->where('code', 'ATM-2026-002')->value('status'));
    }

    public function test_sitemap_and_logo(): void
    {
        $xml = $this->get('/api/public/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $this->assertStringContainsString('<loc>http://localhost:5180/jobs/atmata/ATM-2026-001</loc>', $xml);
        $this->assertStringContainsString('<loc>http://localhost:5180/c/alfarada</loc>', $xml);
        $this->assertStringNotContainsString('REQ-2026-041', $xml);
        $this->get('/api/public/companies/atmata/logo')->assertOk();
        $this->get('/api/public/companies/nope/logo')->assertNotFound();
    }
}
