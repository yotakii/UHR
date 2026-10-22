<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** M10 العهد: السجل، التسليم والتوقيع، الاسترجاع بالحالة، الصيانة والاستبعاد، الاستيراد الجزئي (D-13) */
class CustodyTest extends TestCase
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
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk();
    }

    private function emp(string $code): Employee { return Employee::allCompanies()->where('code', $code)->firstOrFail(); }

    private static function signature(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function test_register_summary_and_new_asset(): void
    {
        $this->login('samer.halabi@alfarada.sy');
        $this->getJson('/api/hr/custody')->assertForbidden();
        $this->login('hr@alfarada.sy');
        $d = $this->getJson('/api/hr/custody')->assertOk()->json();
        $this->assertSame(Asset::allCompanies()->whereHas('company', fn ($q) => $q->where('slug', 'alfarada'))->count(), $d['summary']['total']);
        $this->assertSame(8, $d['summary']['available']);
        $this->assertCount(5, $d['categories']);
        $this->assertSame(1, count($this->getJson('/api/hr/custody?q=Kia&status=assigned')->json('data')) >= 1 ? 1 : 0);

        $cat = $d['categories'][0]['id'];
        $new = ['name_ar' => 'طابعة HP', 'name_en' => 'HP printer', 'asset_category_id' => $cat, 'serial' => 'PRN-001', 'value' => 300, 'condition' => 'good'];
        $a = $this->postJson('/api/hr/custody', $new)->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^AST-\d{4}$/', $a['tag']);
        $this->assertSame('available', $a['status']);
        $this->postJson('/api/hr/custody', ['serial' => 'PRN-001'] + $new)->assertUnprocessable()->assertJsonPath('code', 'duplicate_serial');
        $this->assertSame('created', AssetMovement::allCompanies()->where('asset_id', $a['id'])->value('action'));
    }

    public function test_issue_sign_return_maintenance_and_retire(): void
    {
        $this->login('hr@alfarada.sy');
        $a = Asset::allCompanies()->where('status', 'available')->orderBy('id')->firstOrFail();
        $samer = $this->emp('UHR-1007');
        $this->postJson("/api/hr/custody/{$a->id}/issue", ['employee_id' => $samer->id, 'note' => 'للعمل من المنزل'])->assertOk()->assertJsonPath('data.status', 'pending_ack')->assertJsonPath('data.holder.code', 'UHR-1007');
        $this->postJson("/api/hr/custody/{$a->id}/issue", ['employee_id' => $samer->id])->assertStatus(409)->assertJsonPath('code', 'asset_unavailable');
        $this->assertTrue(Notice::where('user_id', User::where('email', 'samer.halabi@alfarada.sy')->value('id'))->where('link', 'docs')->exists());

        $this->login('samer.halabi@alfarada.sy');
        $this->postJson('/api/me/documents/sign', ['doc' => 'asset', 'asset_id' => $a->id, 'signature' => self::signature()])->assertOk();
        $this->assertSame('assigned', $a->fresh()->status);

        $this->login('hr@alfarada.sy');
        $this->postJson("/api/hr/custody/{$a->id}/return", ['condition' => 'broken'])->assertUnprocessable();
        $this->postJson("/api/hr/custody/{$a->id}/return", ['condition' => 'damaged', 'note' => 'شاشة مكسورة'])->assertOk()->assertJsonPath('data.status', 'maintenance')->assertJsonPath('data.holder', null);
        $this->postJson("/api/hr/custody/{$a->id}/issue", ['employee_id' => $samer->id])->assertStatus(409);
        $this->postJson("/api/hr/custody/{$a->id}/status", ['status' => 'available'])->assertOk()->assertJsonPath('data.condition', 'good');
        $this->postJson("/api/hr/custody/{$a->id}/status", ['status' => 'retired'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/hr/custody/{$a->id}/status", ['status' => 'retired', 'note' => 'انتهى عمره الافتراضي'])->assertOk()->assertJsonPath('data.status', 'retired');
        $h = $this->getJson("/api/hr/custody/{$a->id}")->assertOk()->json('history');
        $this->assertSame(['retired', 'available', 'returned', 'signed', 'issued', 'created'], array_column($h, 'action'));
        $this->assertSame('شاشة مكسورة', $h[2]['note']);
        $this->assertTrue(collect($samer->fresh()->logs)->contains(fn ($l) => str_contains($l->text_ar, 'استرجاع عهدة')));
        $this->get('/api/hr/custody/export')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'assets.export')->exists());
    }

    public function test_import_keeps_good_rows_and_reports_errors(): void
    {
        $this->login('hr@alfarada.sy');
        $this->get('/api/hr/custody/import/template')->assertOk();
        $existing = Asset::allCompanies()->whereNotNull('serial')->value('serial');
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->addRow(Row::fromValues(['tag — رمز', 'name_ar — اسم*', 'name_en — en*', 'category — فئة*', 'serial — تسلسلي', 'value — قيمة*', 'purchased_at', 'condition', 'employee_code', 'notes']));
        $w->addRow(Row::fromValues(['', 'طابعة ليزر', 'Laser printer', 'peripherals', 'LP-1', '220', '2025-02-01', 'good', '', '']));
        $w->addRow(Row::fromValues(['', 'هاتف آيفون', 'iPhone', 'phones', 'IP-1', '900', '', 'good', 'UHR-1007', 'للمبيعات']));
        $w->addRow(Row::fromValues(['', 'مكتب', 'Desk', 'furniture', 'D-1', '150', '', 'good', '', '']));            // فئة غير موجودة
        $w->addRow(Row::fromValues(['', 'حاسب مكرر', 'Duplicate', 'computers', $existing, '500', '', 'good', '', '']));  // تسلسلي مكرر
        $w->addRow(Row::fromValues(['', 'شاشة', 'Monitor', 'peripherals', 'LP-1', '100', '', 'good', 'UHR-9999', '']));   // مكرر بالملف + موظف غير موجود
        $w->close();
        $file = fn () => new UploadedFile($path, 'assets.xlsx', null, null, true);

        $p = $this->post('/api/hr/custody/import/preview', ['file' => $file()])->assertOk()->json();
        $this->assertSame([2, 3], [$p['ok'], $p['errors']]);
        $this->assertArrayHasKey('category', $p['rows'][2]['errors']);
        $this->assertArrayHasKey('serial', $p['rows'][3]['errors']);
        $this->assertEqualsCanonicalizing(['serial', 'employee_code'], array_keys($p['rows'][4]['errors']));

        $before = Asset::allCompanies()->count();
        $r = $this->post('/api/hr/custody/import', ['file' => $file()])->assertCreated()->json();
        $this->assertSame([2, 3], [$r['created'], $r['failed']]);
        $this->assertCount(3, $r['errors']);
        $this->assertSame($before + 2, Asset::allCompanies()->count());
        $phone = Asset::allCompanies()->where('serial', 'IP-1')->firstOrFail();
        $this->assertSame(['pending_ack', $this->emp('UHR-1007')->id], [$phone->status, $phone->employee_id]);
        $this->assertSame('available', Asset::allCompanies()->where('serial', 'LP-1')->value('status'));
        $log = AuditLog::where('action', 'assets.imported')->latest('id')->firstOrFail();
        $this->assertSame([2, 3], [$log->after['ok'], $log->after['failed']]);
    }
}
