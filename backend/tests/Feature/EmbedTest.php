<?php

namespace Tests\Feature;

use App\Models\JobOpening;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** كود تضمين شواغر الشركة في موقعها: الصفحة العامة، المواقع المسموحة، والإعدادات */
class EmbedTest extends TestCase
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

    public function test_embed_page_settings_and_allowed_sites(): void
    {
        $pub = JobOpening::allCompanies()->whereHas('company', fn ($q) => $q->where('slug', 'uniqueness'))->onCompanyPage()->count();
        $this->assertGreaterThan(0, $pub);

        $r = $this->get('/api/public/embed/uniqueness?lang=en&theme=dark')->assertOk();
        $this->assertStringContainsString('text/html', $r->headers->get('Content-Type'));
        $this->assertSame('frame-ancestors *', $r->headers->get('Content-Security-Policy'));
        $this->assertSame($pub, substr_count($r->getContent(), 'class="job"'));
        $this->assertStringContainsString('data-theme="dark"', $r->getContent());
        $this->assertStringContainsString('?src=embed', $r->getContent());
        // الشواغر الداخلية لا تظهر أبداً
        $internal = JobOpening::allCompanies()->whereHas('company', fn ($q) => $q->where('slug', 'uniqueness'))->where('visibility', 'internal')->first();
        if ($internal) $this->assertStringNotContainsString('/'.$internal->code.'?', $r->getContent());
        $this->get('/api/public/embed/uniqueness?limit=1')->assertOk()->assertSee('/c/uniqueness', false);
        $this->get('/api/public/embed/nope')->assertNotFound();
        $this->get('/api/public/embed.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        // الموظف العادي لا يغيّر الإعداد
        $this->login('samer.halabi@uniqueness.sy');
        $this->putJson('/api/hr/jobs/embed', [])->assertForbidden();

        $this->login('admin@uniqueness.sy');
        $this->getJson('/api/hr/jobs/embed')->assertOk()->assertJsonPath('slug', 'uniqueness')->assertJsonPath('data.enabled', true);
        $base = ['enabled' => true, 'limit' => 10, 'theme' => 'light', 'show_logo' => true, 'show_filters' => false, 'color' => '#123abc'];
        $this->putJson('/api/hr/jobs/embed', $base + ['sites' => ['not a site']])->assertStatus(422)->assertJsonPath('code', 'invalid_site');
        $this->putJson('/api/hr/jobs/embed', $base + ['sites' => ['https://www.uniqueness.sy/careers', 'shop.example.com', '']])->assertOk()
            ->assertJsonPath('data.sites', ['www.uniqueness.sy', 'shop.example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.embed']);

        $csp = $this->get('/api/public/embed/uniqueness')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("'self' https://www.uniqueness.sy", $csp);
        $this->assertStringContainsString('https://shop.example.com', $csp);
        $this->assertStringContainsString('#123abc', $this->get('/api/public/embed/uniqueness')->getContent());

        $this->putJson('/api/hr/jobs/embed', array_merge($base, ['enabled' => false, 'sites' => []]))->assertOk();
        $this->get('/api/public/embed/uniqueness')->assertNotFound();
    }
}
