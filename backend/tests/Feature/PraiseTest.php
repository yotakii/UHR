<?php

namespace Tests\Feature;

use App\Models\Celebration;
use App\Models\Employee;
use App\Models\PointLog;
use App\Models\SpacePost;
use App\Services\Space\Celebrations;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 12b: «شكر زميل» بقيم الشركة والنقاط، والاحتفالات التلقائية في الساحة */
class PraiseTest extends TestCase
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

    private function emp(string $email): Employee { return Employee::allCompanies()->where('email', $email)->firstOrFail(); }

    public function test_praise_posts_in_company_space_adds_points_and_respects_limits(): void
    {
        $samer = $this->emp('samer.halabi@uniqueness.sy'); $dana = $this->emp('dana.shami@uniqueness.sy');
        $before = (int) $samer->points;
        $this->login('dana.shami@uniqueness.sy');
        $this->getJson('/api/space')->assertOk()->assertJsonPath('praise.enabled', true)->assertJsonPath('praise.left', 10)->assertJsonPath('praise.points', 5);
        $this->getJson('/api/space/colleagues?q=سامر')->assertOk()->assertJsonPath('data.0.id', $samer->id);
        $this->postJson('/api/space/praise', ['recipients' => [$dana->id], 'value' => 'teamwork', 'message' => 'شكراً لنفسي على العمل'])->assertUnprocessable()->assertJsonPath('code', 'self');
        $this->postJson('/api/space/praise', ['recipients' => [$samer->id], 'value' => 'nope', 'message' => 'شكراً جزيلاً على المساعدة'])->assertUnprocessable();
        $this->postJson('/api/space/praise', ['recipients' => [$samer->id], 'value' => 'teamwork', 'message' => 'قصير'])->assertUnprocessable();
        $id = $this->postJson('/api/space/praise', ['recipients' => [$samer->id], 'value' => 'teamwork', 'message' => 'شكراً على مساعدتي في إعداد التقرير'])
            ->assertCreated()->assertJsonPath('data.kind', 'praise')->assertJsonPath('data.meta.value.key', 'teamwork')->assertJsonPath('data.meta.recipients.0.employee_id', $samer->id)
            ->assertJsonPath('data.community.kind', 'company')->assertJsonPath('praise.left', 9)->json('data.id');
        $this->assertSame($before + 5, (int) $samer->fresh()->points);
        $this->assertSame(5, (int) PointLog::allCompanies()->where('employee_id', $samer->id)->where('source', 'praise')->sum('points'));

        $this->login('samer.halabi@uniqueness.sy');
        $this->getJson('/api/space?filter=praise')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('praise_top.0.employee_id', $samer->id);
        $this->getJson('/api/me/rewards')->assertOk()->assertJsonPath('points', $before + 5);

        // الحد الشهري + النقاط 0 = بلا نقاط
        $this->login('hr@uniqueness.sy');
        $cfg = $this->getJson('/api/space/settings')->assertOk()->json();
        $this->putJson('/api/space/praise-settings', ['praise' => ['values' => $cfg['praise']['values'], 'points' => 0, 'monthly_limit' => 1] + $cfg['praise'], 'celebrations' => $cfg['celebrations']])
            ->assertOk()->assertJsonPath('praise.monthly_limit', 1);
        $this->postJson('/api/space/praise', ['recipients' => [$samer->id, $dana->id], 'value' => 'excellence', 'message' => 'عمل متقن في المشروع كله'])->assertCreated();
        $this->postJson('/api/space/praise', ['recipients' => [$samer->id], 'value' => 'excellence', 'message' => 'عمل متقن في المشروع كله'])->assertStatus(409)->assertJsonPath('code', 'praise_limit');
        $this->assertSame($before + 5, (int) $samer->fresh()->points);
        $this->putJson('/api/space/praise-settings', ['praise' => ['values' => [['key' => 'a', 'ar' => 'قيمة', 'color' => 'brand'], ['key' => 'a', 'ar' => 'أخرى', 'color' => 'brand']]] + $cfg['praise'], 'celebrations' => $cfg['celebrations']])->assertUnprocessable();
    }

    public function test_daily_celebrations_promotion_and_hidden_birthday(): void
    {
        $samer = $this->emp('samer.halabi@uniqueness.sy'); $dana = $this->emp('dana.shami@uniqueness.sy');
        $samer->update(['dob' => '1990-10-12']);
        $dana->update(['dob' => '1992-10-12', 'hide_birthday' => true, 'hire_date' => '2023-10-12']);
        Tenant::run($samer->company_id, function () use ($samer, $dana) {
            $c = app(Celebrations::class);
            $this->assertSame(2, $c->run());   // عيد ميلاد سامر + سنوية دانا
            $this->assertSame(0, $c->run());   // مرة واحدة في اليوم
            $b = SpacePost::where('kind', 'celebration')->get()->keyBy(fn ($p) => $p->meta['type']);
            $this->assertSame([$samer->id], array_column($b['birthday']->meta['people'], 'employee_id'));   // دانا أخفت تاريخها
            $this->assertSame(3, collect($b['anniversary']->meta['people'])->firstWhere('employee_id', $dana->id)['years']);
            $this->assertNull($b['birthday']->user_id);
        });

        $this->login('samer.halabi@uniqueness.sy');
        $row = collect($this->getJson('/api/space?filter=celebrations')->assertOk()->json('data'))->firstWhere('meta.type', 'birthday');
        $this->assertTrue($row['author']['system']);
        $this->assertFalse($row['can']['report']);
        $this->postJson("/api/space/posts/{$row['id']}/comments", ['body' => 'شكراً للجميع'])->assertCreated();
        $this->putJson('/api/me/account', ['hide_birthday' => true])->assertOk();
        $this->assertTrue((bool) $samer->fresh()->hide_birthday);
        $this->assertTrue($this->getJson('/api/me/account')->json('data.employee.hide_birthday'));

        // ترقية بخيار الإعلان
        $this->login('hr@uniqueness.sy');
        $this->putJson("/api/hr/employees/{$samer->id}", array_merge($this->profilePayload($samer->fresh()), ['title_ar' => 'مهندس شبكات أول', 'announce_promotion' => true]))->assertOk();
        $this->assertSame(1, Celebration::allCompanies()->where('kind', 'promotion')->count());
        $this->putJson("/api/hr/employees/{$samer->id}", array_merge($this->profilePayload($samer->fresh()), ['title_ar' => 'مهندس شبكات رئيسي']))->assertOk();
        $this->assertSame(1, Celebration::allCompanies()->where('kind', 'promotion')->count());   // بلا خيار الإعلان
    }

    private function profilePayload(Employee $e): array
    {
        return ['name' => $e->name, 'name_en' => $e->name_en, 'gender' => $e->gender, 'email' => $e->email, 'phone' => $e->phone, 'national_id' => $e->national_id,
            'dob' => $e->dob?->toDateString(), 'department_id' => $e->department_id, 'manager_id' => $e->manager_id, 'location_id' => $e->location_id,
            'title_ar' => $e->title_ar, 'title_en' => $e->title_en, 'hire_date' => $e->hire_date?->toDateString(), 'city' => $e->city ?? null];
    }
}
