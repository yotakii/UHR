<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Notice;
use App\Models\PolicyDocument;
use App\Models\PolicyParagraph;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Admin\AuditArchive;
use App\Services\Admin\Backups;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** M16 (11b): سجل التدقيق والأرشيف، مكتبة السياسات (D-26)، النسخ الاحتياطي (D-27)، تصدير الهيكل (D-33) */
class AdminToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, string $password = '123456'): void
    {
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    public function test_audit_log_search_window_archive_and_export(): void
    {
        $c = $this->useCompany();
        $admin = User::where('email', 'admin@alfarada.sy')->first();
        $old = AuditLog::create(['company_id' => $c->id, 'user_id' => $admin->id, 'action' => 'employee.updated', 'subject_type' => \App\Models\Employee::class, 'subject_id' => 1,
            'before' => ['title_ar' => 'قديم'], 'after' => ['title_ar' => 'جديد'], 'at' => now()->subDays(70)]);
        $other = $this->makeCompany('acme');
        AuditLog::create(['company_id' => $other->id, 'action' => 'auth.login', 'at' => now()]);
        Tenant::forget();

        $this->login('admin@alfarada.sy');
        $r = $this->getJson('/api/hr/admin/audit')->assertOk()->json();
        $this->assertSame(30, $r['settings']['visible_days']);
        $this->assertSame('تسجيل دخول', collect($r['data'])->firstWhere('action', 'auth.login')['label']['ar']);
        $this->assertNotContains($old->id, array_column($r['data'], 'id'));
        $this->assertTrue(collect($r['data'])->every(fn ($x) => AuditLog::find($x['id'])->company_id === $c->id));
        $this->assertContains('auth', array_column($r['groups'], 'key'));

        $arc = $this->getJson('/api/hr/admin/audit?archive=1&group=employee')->assertOk()->json('data');
        $this->assertSame('تعديل موظف', collect($arc)->firstWhere('id', $old->id)['label']['ar']);
        $this->getJson('/api/hr/admin/audit?archive=1&q=قديم')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/hr/admin/audit?user={$admin->id}&group=auth")->assertOk()->assertJsonPath('data.0.user.email', 'admin@alfarada.sy');

        $this->get('/api/hr/admin/audit/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.exported', 'company_id' => $c->id]);

        // الأرشيف الشهري الدائم: ملف للشهر الذي خرج من النافذة، والسطر يبقى في القاعدة
        Tenant::run($c->id, fn () => app(AuditArchive::class)->company($c->id));
        $files = $this->getJson('/api/hr/admin/audit/archives')->assertOk()->json('data');
        $month = now()->subDays(70)->format('Y-m');
        $this->assertContains($month, array_column($files, 'month'));
        $this->get("/api/hr/admin/audit/archives/{$month}")->assertOk();
        $this->assertNotNull(AuditLog::find($old->id));

        $this->putJson('/api/hr/admin/audit/settings', ['visible_days' => 3])->assertStatus(422);
        $this->putJson('/api/hr/admin/audit/settings', ['visible_days' => 90])->assertOk()->assertJsonPath('data.visible_days', 90);
        $this->getJson('/api/hr/admin/audit?group=employee')->assertOk()->assertJsonFragment(['id' => $old->id]);

        // مدير الموارد البشرية: عرض بلا أرشيف ولا تصدير؛ الموظف: ممنوع
        $this->login('hr@alfarada.sy');
        $this->putJson('/api/hr/admin/audit/settings', ['visible_days' => 30])->assertOk();
        $this->getJson('/api/hr/admin/audit?archive=1&q=قديم')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('can.archive', false);
        $this->get('/api/hr/admin/audit/export')->assertForbidden();
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/admin/audit')->assertForbidden();
    }

    public function test_policy_library_publish_search_links_and_reads(): void
    {
        // وثائق الشركة مبذورة ومنشورة، وفقرة الدوام مربوطة بإعداد الوردية
        $this->login('samer.halabi@alfarada.sy');
        $list = $this->getJson('/api/policies')->assertOk()->json('data');
        $this->assertCount(5, $list);
        $hit = collect($this->getJson('/api/policies/search?q='.urlencode('الساعة التاسعة'))->assertOk()->json('data'))->first();
        $this->assertSame('HR.01-01', $hit['document']['code']);
        $this->assertSame('shift', $hit['rule']['key']);
        $this->assertSame('/hr/settings/policies', $hit['rule']['path']);
        // التطبيع: «الإجازه» بلا همزة/تاء مربوطة تجد «الإجازة»
        $this->assertNotEmpty($this->getJson('/api/policies/search?q='.urlencode('الاجازه المرضيه'))->json('data'));
        $doc = $this->getJson("/api/policies/{$hit['document']['id']}")->assertOk()->json('data');
        $this->assertNotNull($doc['version']['read_at']);
        $this->assertNull($doc['version']['reads']);
        $this->postJson('/api/policies', ['title_ar' => 'x', 'category' => 'policy'])->assertForbidden();

        // HR يرفع سياسة جديدة (مسودة لا يراها الموظف) ثم ينشرها ← إشعار الموظفين
        $this->login('hr@alfarada.sy');
        $txt = UploadedFile::fake()->createWithContent('remote.txt', "سياسة العمل عن بعد\n\nيحق للموظف العمل من المنزل يومين في الأسبوع بموافقة المدير المباشر.\n\nتُسجَّل أيام العمل عن بعد في النظام.");
        $d = $this->post('/api/policies', ['code' => 'HR.01-09', 'title_ar' => 'العمل عن بعد', 'title_en' => 'Remote work', 'category' => 'policy', 'issue' => '01', 'file' => $txt], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertCount(3, $d['version']['paragraphs']);
        $vid = $d['version']['id']; $pid = $d['version']['paragraphs'][1]['id'];
        $this->putJson("/api/policies/paragraphs/{$pid}", ['rule_key' => 'nope'])->assertStatus(422);
        $this->putJson("/api/policies/paragraphs/{$pid}", ['rule_key' => 'work_days', 'rule_note' => 'أيام العمل'])->assertOk()->assertJsonPath('data.rule.key', 'work_days');
        $fileId = $d['version']['file']['id'];

        $this->login('samer.halabi@alfarada.sy');
        $this->getJson("/api/policies/{$d['id']}")->assertNotFound();
        $this->get("/api/files/{$fileId}")->assertForbidden();
        $this->assertEmpty($this->getJson('/api/policies/search?q='.urlencode('من المنزل'))->json('data'));

        $this->login('hr@alfarada.sy');
        $this->postJson("/api/policies/versions/{$vid}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $samer = User::where('email', 'samer.halabi@alfarada.sy')->first();
        $this->assertTrue(Notice::where('user_id', $samer->id)->where('link', "policy:{$d['id']}")->exists());

        // إصدار ثانٍ: مسودة واحدة فقط، ثم النشر يؤرشف السابق وينقل ربط الفقرة ذات النص نفسه
        $txt2 = UploadedFile::fake()->createWithContent('remote2.txt', "سياسة العمل عن بعد\n\nيحق للموظف العمل من المنزل يومين في الأسبوع بموافقة المدير المباشر.\n\nيُعتمد العمل عن بعد من الموارد البشرية.");
        $d2 = $this->post("/api/policies/{$d['id']}/versions", ['issue' => '02', 'file' => $txt2], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->postJson("/api/policies/{$d['id']}/versions", ['issue' => '03', 'text' => 'نص'])->assertStatus(409)->assertJsonPath('code', 'draft_exists');
        $this->postJson("/api/policies/versions/{$d2['version']['id']}/publish")->assertOk();
        $this->assertSame('archived', PolicyVersion::find($vid)->status);
        $this->assertSame('work_days', PolicyParagraph::where('version_id', $d2['version']['id'])->where('n', 2)->value('rule_key'));
        $this->postJson("/api/policies/versions/{$vid}", ['note' => 'x'])->assertStatus(409)->assertJsonPath('code', 'version_locked');

        // الاطلاع: الموظف يقرأ ← HR يرى العدد ومن قرأ
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson("/api/policies/{$d['id']}")->assertOk()->assertJsonPath('data.version.number', 2);
        $this->assertNotEmpty($this->getJson('/api/policies/search?q='.urlencode('من المنزل'))->json('data'));
        $this->login('hr@alfarada.sy');
        $this->getJson("/api/policies/{$d['id']}")->assertOk()->assertJsonPath('data.version.reads.n', 2); // سامر + رشا (HR موظفة أيضاً)
        $reads = $this->getJson("/api/policies/versions/{$d2['version']['id']}/reads")->assertOk()->json('data');
        $this->assertNotNull(collect($reads)->firstWhere('id', $samer->id)['read_at']);

        // الأرشفة تخفي الوثيقة عن الموظفين
        $this->postJson("/api/policies/{$d['id']}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson("/api/policies/{$d['id']}")->assertNotFound();
        $this->assertCount(5, $this->getJson('/api/policies')->json('data'));
    }

    public function test_company_backup_needs_salary_verification_and_skips_secrets(): void
    {
        $this->login('admin@alfarada.sy');
        $this->getJson('/api/hr/admin/backups')->assertOk()->assertJsonPath('settings.keep', 5);
        $b = $this->postJson('/api/hr/admin/backups')->assertCreated()->assertJsonPath('data.status', 'ok')->json('data');
        $this->assertGreaterThan(1000, $b['meta']['rows']);
        $this->get("/api/hr/admin/backups/{$b['id']}/download")->assertForbidden()->assertJsonPath('code', 'salary_locked');

        $row = Backup::find($b['id']);
        $zip = new \ZipArchive(); $zip->open(Storage::disk('local')->path($row->path));
        $users = json_decode($zip->getFromName('tables/users.json'), true);
        $this->assertNotEmpty($users);
        $this->assertArrayNotHasKey('password', $users[0]);
        $this->assertNotFalse($zip->getFromName('tables/employees.json'));
        $this->assertSame('alfarada', json_decode($zip->getFromName('manifest.json'), true)['company']['slug']);
        $zip->close();

        $this->unlockSalaries();
        $this->get("/api/hr/admin/backups/{$b['id']}/download")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded']);

        // الاحتفاظ بآخر keep نسخة
        $this->putJson('/api/hr/admin/backups/settings', ['keep' => 1, 'include_files' => false])->assertOk();
        $this->postJson('/api/hr/admin/backups')->assertCreated();
        $this->assertSame('removed', Backup::find($b['id'])->status);
        $this->assertFalse(Storage::disk('local')->exists($row->path));

        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/hr/admin/backups')->assertForbidden();
    }

    public function test_platform_full_backup_and_schedule(): void
    {
        $svc = app(Backups::class);
        $this->assertTrue($svc->due()); // 10:30 بعد وقت النسخ 02:30 ولا نسخة آلية اليوم
        $this->login('platform@atmata.sy');
        $this->putJson('/api/platform/backups/settings', ['enabled' => true, 'time' => '25:00', 'keep' => 7])->assertStatus(422);
        $this->putJson('/api/platform/backups/settings', ['enabled' => true, 'time' => '11:00', 'keep' => 7])->assertOk()->assertJsonPath('settings.time', '11:00');
        $this->assertFalse($svc->due());

        $b = $this->postJson('/api/platform/backups')->json('data');
        if ($b['status'] === 'failed' && str_contains((string) $b['error'], 'version mismatch')) $this->markTestSkipped('pg_dump is older than the database server: rebuild the api image.');
        $this->assertSame('ok', $b['status'], (string) $b['error']);
        $this->assertGreaterThan(10000, $b['size']);
        $this->get("/api/platform/backups/{$b['id']}/download")->assertForbidden()->assertJsonPath('code', 'salary_locked');
        $this->unlockSalaries(false);
        $this->get("/api/platform/backups/{$b['id']}/download")->assertOk();

        $this->login('admin@alfarada.sy');
        $this->getJson('/api/platform/backups')->assertForbidden();
        $this->getJson('/api/hr/admin/backups')->assertOk()->assertJsonPath('server.last.id', $b['id']);
        $this->get("/api/hr/admin/backups/{$b['id']}/download")->assertNotFound();
    }

    public function test_org_chart_export_is_logged(): void
    {
        $this->login('hr@alfarada.sy');
        $this->getJson('/api/hr/org')->assertOk()->assertJsonPath('chart.mode', 'names_titles')->assertJsonPath('can.print', true);
        $this->postJson('/api/hr/org/export', ['scope' => 'department', 'dept' => 'hr', 'levels' => 3, 'mode' => 'titles', 'pages' => 2])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.exported']);
        $this->putJson('/api/hr/org/settings', ['mode' => 'titles', 'levels' => 4])->assertOk()->assertJsonPath('data.levels', 4);
        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/hr/org/export', ['scope' => 'company', 'levels' => 3, 'mode' => 'titles'])->assertForbidden();
    }
}
