<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Notice;
use App\Models\User;
use App\Notifications\ResetPasswordNotice;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** متابعة الطلب برقم المتابعة + البريد، ونطاقات البريد الرسمية وطريقة استعادة كلمة المرور */
class TrackAndResetTest extends TestCase
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

    private function logout(): void { $this->postJson('/api/auth/logout'); $this->app['auth']->forgetGuards(); }

    public function test_track_by_reference_and_email(): void
    {
        $c = Candidate::allCompanies()->where('email', 'leen.ali@mail.sy')->firstOrFail();
        $this->withHeader('Accept-Language', 'ar');
        $r = $this->postJson('/api/public/track', ['ref' => ' '.strtolower($c->ref).' ', 'email' => 'LEEN.ALI@mail.sy'])->assertOk();
        $this->assertSame($c->ref, $r->json('data.ref'));
        $this->assertContains($r->json('data.status'), ['received', 'review', 'interview', 'offer', 'hired', 'closed']);
        $this->assertSame('offer', $r->json('data.status'));   // ليان لديها عرض صادر
        $this->assertTrue($r->json('data.has_account'));
        $this->assertArrayNotHasKey('match', $r->json('data'));
        // بريد مختلف أو رقم خاطئ ← نفس الرد
        $this->postJson('/api/public/track', ['ref' => $c->ref, 'email' => 'someone@mail.sy'])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->postJson('/api/public/track', ['ref' => 'X-0000', 'email' => 'leen.ali@mail.sy'])->assertStatus(404);
        $this->postJson('/api/public/track', ['ref' => '', 'email' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['ref', 'email']);
        // محاولات محدودة
        for ($i = 0; $i < 8; $i++) $this->postJson('/api/public/track', ['ref' => 'X-'.$i, 'email' => 'a@b.sy']);
        $this->postJson('/api/public/track', ['ref' => $c->ref, 'email' => 'leen.ali@mail.sy'])->assertStatus(429);
    }

    public function test_company_email_domains_and_reset_through_hr(): void
    {
        Notification::fake();
        $this->login('admin@uniqueness.sy');
        $this->putJson('/api/hr/admin/users/settings', ['invite_hours' => 72, 'email_domains' => ['bad domain']])->assertStatus(422);
        $this->putJson('/api/hr/admin/users/settings', ['invite_hours' => 72, 'email_domains' => ['@Uniqueness.sy'], 'reset_mode' => 'email'])->assertOk()
            ->assertJsonPath('settings.email_domains', ['uniqueness.sy'])->assertJsonPath('settings.reset_mode', 'email');
        // حساب جديد ببريد خارجي مرفوض، وبريد الشركة مقبول
        $this->postJson('/api/hr/admin/users', ['email' => 'ops@gmail.com', 'name' => 'العمليات', 'roles' => ['executive']])->assertStatus(422)->assertJsonPath('code', 'email_domain');
        $this->postJson('/api/hr/admin/users', ['email' => 'ops@uniqueness.sy', 'name' => 'العمليات', 'roles' => ['executive']])->assertCreated();
        // تعديل بريد موظف إلى بريد شخصي مرفوض
        $this->login('hr@uniqueness.sy');
        $samer = User::where('email', 'samer.halabi@uniqueness.sy')->firstOrFail();
        $e = \App\Models\Employee::findOrFail($samer->employee_id);
        $in = ['name' => $e->name, 'name_en' => $e->name_en, 'gender' => $e->gender, 'title_ar' => $e->title_ar, 'title_en' => $e->title_en, 'department_id' => $e->department_id,
            'location_id' => $e->location_id, 'manager_id' => $e->manager_id, 'phone' => $e->phone, 'national_id' => $e->national_id, 'dob' => $e->dob?->toDateString()];
        $this->putJson('/api/hr/employees/'.$e->id, $in + ['email' => 'samer@gmail.com'])->assertStatus(422)->assertJsonPath('code', 'email_domain');
        $this->putJson('/api/hr/employees/'.$e->id, $in + ['email' => 'samer.halabi@uniqueness.sy'])->assertOk();

        // «نسيت كلمة المرور» ببريد الشركة ← رابط إلى البريد نفسه
        $this->logout();
        $this->postJson('/api/auth/forgot', ['email' => 'samer.halabi@uniqueness.sy'])->assertOk();
        Notification::assertSentTo($samer, ResetPasswordNotice::class);

        // طريقة الموارد البشرية ← لا رسالة؛ إشعار للموارد البشرية برابط صفحة المستخدمين
        $this->login('admin@uniqueness.sy');
        $this->putJson('/api/hr/admin/users/settings', ['invite_hours' => 72, 'reset_mode' => 'hr'])->assertOk();
        $this->logout();
        Notification::fake();
        $this->postJson('/api/auth/forgot', ['email' => 'samer.halabi@uniqueness.sy'])->assertOk()->assertJsonStructure(['message', 'hint']);
        Notification::assertNothingSent();
        $n = Notice::where('audience', 'hr')->where('link', 'users:samer.halabi@uniqueness.sy')->first();
        $this->assertNotNull($n);
        $this->assertSame($samer->company_id, $n->company_id);
        // يراه من يملك إدارة المستخدمين
        $this->login('admin@uniqueness.sy');
        $this->assertTrue(collect($this->getJson('/api/notices')->json('data'))->contains(fn ($x) => ($x['link'] ?? null) === 'users:samer.halabi@uniqueness.sy'));

        // المتقدم (بلا شركة) يصله الرابط دائماً على بريده
        $this->logout();
        $leen = User::where('email', 'leen.ali@mail.sy')->firstOrFail();
        $this->postJson('/api/auth/forgot', ['email' => 'leen.ali@mail.sy'])->assertOk();
        Notification::assertSentTo($leen, ResetPasswordNotice::class);
    }
}
