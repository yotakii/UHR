<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AttendanceDay;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 14 — التحقق النهائي (SRS §9.4): رحلة واحدة كاملة عبر الـ API كما يمر بها المستخدمون:
 * متقدم جديد يقدّم من البوابة العامة ← يظهر في لوحة المتقدمين ← مقابلة F-25 ← عرض F-29 ← يوقّع المتقدم ←
 * أربعة اعتمادات F-31 كلٌّ من حسابه ← موظف جديد يدخل بنفس بياناته ← يوقّع العهدة وF-22 وF-20 والميثاق ←
 * يسجّل حضوره من البوابة ← إجازة طارئة بمستويين تصله إشعاراتها ← يسأل «المساعد» عن رصيده ← كل عملية في سجل التدقيق.
 * (مسار الاستقالة الكامل HR.01-03 في ExitTest، والمخالفات في DisciplineTest، والعزل في TenantIsolationTest.)
 */
class AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Storage::fake('local');
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withHeader('Accept-Language', 'ar');
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email, string $password = '123456'): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    private function staff(string $code): string { return User::whereHas('employee', fn ($q) => $q->where('code', $code))->value('email'); }

    private static function sig(): string
    {
        $img = imagecreatetruecolor(240, 80);
        imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); $png = ob_get_clean();
        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function test_from_public_application_to_an_employee_working_in_the_portal(): void
    {
        // 1) البوابة العامة: طلب ناقص يُرفض برسائل تحت الحقول، والكامل يُنشئ الملف والحساب
        $form = ['name' => 'رنا خالد السيد', 'name_en' => 'Rana Al-Sayed', 'gender' => 'F', 'marital' => 'single', 'nationality' => 'سورية',
            'dob' => '1996-05-20', 'pob' => 'دمشق', 'national_id' => '09080706050', 'blood' => 'A+', 'mobile' => '+963 944 222 333', 'email' => 'rana.sayed@mail.sy',
            'address' => 'دمشق — المالكي', 'degree' => 2, 'institute' => 'جامعة دمشق', 'major' => 'محاسبة', 'grad_year' => 2018, 'grade' => 'جيد جداً', 'english' => 'good', 'years_exp' => 5,
            'experience' => [['company' => 'شركة الشرق', 'position' => 'محاسبة', 'from' => 2019, 'to' => 2026, 'reason' => 'تطور مهني']],
            'ref_name' => 'أ. سمير حداد', 'ref_job' => 'مدير سابق', 'ref_phone' => '+963 944 100 200',
            'shifts' => 0, 'travel' => 1, 'public_sector' => 0, 'conviction' => 0, 'salary' => 750, 'availability' => '2026-11-01', 'source' => 'website',
            'cv' => UploadedFile::fake()->create('Rana_CV.pdf', 200, 'application/pdf'),
            'password' => 'Rana2026pw', 'password_confirmation' => 'Rana2026pw', 'agree_info' => 1, 'agree_medical' => 1, 'agree_data' => 1, 'keep_cv' => 1];
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/public/jobs/alfarada/REQ-2026-034/apply', ['email' => 'x'] + array_diff_key($form, ['cv' => 1, 'name' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $ref = $this->withHeaders(['Accept' => 'application/json'])->post('/api/public/jobs/alfarada/REQ-2026-034/apply', $form)->assertCreated()->json('data.ref');
        $cand = Candidate::allCompanies()->where('ref', $ref)->firstOrFail();

        // 2) يظهر فوراً في لوحة المتقدمين، ويُنقل للمقابلة ثم تُعبّأ F-25 فيصبح «فني»
        $this->login('hr@alfarada.sy');
        $this->assertContains($cand->id, collect($this->getJson('/api/hr/candidates')->assertOk()->json('data'))->pluck('id')->all());
        $this->postJson("/api/hr/candidates/{$cand->id}/move", ['stage' => 'interview'])->assertOk();
        $ratings = array_fill_keys(\App\Services\Recruitment\F25::keys(), 4);
        $this->putJson("/api/hr/candidates/{$cand->id}/interview", ['ratings' => $ratings, 'notes' => 'مقابلة جيدة', 'recommendation' => 'recommended'])->assertOk()->assertJsonPath('data.stage', 'technical');

        // 3) عرض F-29 — مبالغه لا تُكتب قبل التحقق من الهوية (ملاحظة 82)
        $offer = ['title' => 'محاسبة', 'contract' => 'fixed', 'currency' => 'USD', 'basic' => 700, 'housing' => 50, 'transport' => 30, 'fuel' => 0, 'leave_days' => 14, 'start_date' => '2026-11-01'];
        $this->putJson("/api/hr/candidates/{$cand->id}/offer", $offer)->assertStatus(403)->assertJsonPath('code', 'salary_locked');
        $this->unlockSalaries(false);
        \App\Support\Tenant::run($cand->company_id, fn () => \App\Support\Settings::put('rules', ['offer_budget' => 'off'] + (\App\Support\Settings::get('rules') ?? [])));
        $this->putJson("/api/hr/candidates/{$cand->id}/offer", $offer)->assertOk()->assertJsonPath('data.offer.status', 'sent');

        // 4) المتقدمة تقبل العرض وتوقّعه من بوابتها بنفس كلمة مرورها
        $this->login('rana.sayed@mail.sy', 'Rana2026pw');
        $this->postJson("/api/me/applications/{$cand->id}/offer-response", ['status' => 'accepted', 'signature' => self::sig()])->assertOk();

        // 5) F-31: أربعة مستويات بالترتيب، كلٌّ من حسابه
        $steps = $cand->refresh()->appointment()->with('approvals')->firstOrFail()->approvals->pluck('id')->all();
        $this->assertCount(4, $steps);
        foreach (['hr@alfarada.sy', $this->staff('UHR-1004'), $this->staff('UHR-1002'), $this->staff('UHR-1001')] as $i => $who) {
            $this->login($who);
            $r = $this->postJson("/api/hr/approvals/{$steps[$i]}", ['decision' => 'approved', 'reason' => 'مطابق للملاك المعتمد'])->assertOk();
        }
        $emp = Employee::allCompanies()->findOrFail($r->json('data.employee_id'));
        $this->assertSame(['onboarding', 'hired'], [$emp->status, $cand->refresh()->stage]);

        // 6) الموظفة الجديدة تدخل بنفس بياناتها إلى بوابة الموظف وتوقّع وثائق المباشرة
        $this->login('rana.sayed@mail.sy', 'Rana2026pw');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.portal', 'employee');
        $docs = $this->getJson('/api/me/documents')->assertOk()->json();
        $card = Asset::allCompanies()->where('employee_id', $emp->id)->where('status', 'pending_ack')->firstOrFail();
        $this->assertContains($card->id, array_column($docs['pending'], 'id'));
        $this->postJson('/api/me/documents/sign', ['doc' => 'asset', 'asset_id' => $card->id, 'signature' => self::sig()])->assertOk();
        $this->postJson('/api/me/documents/sign', ['doc' => 'f22', 'signature' => self::sig()])->assertOk();
        $this->postJson('/api/me/documents/sign', ['doc' => 'f20', 'signature' => self::sig(), 'mother_name' => 'هدى الأحمد'])->assertOk();
        $this->postJson('/api/me/documents/sign', ['doc' => 'conduct', 'signature' => self::sig()])->assertOk();
        $this->assertSame('assigned', $card->refresh()->status);

        // 7) تسجيل الحضور من البوابة
        $this->postJson('/api/me/checkin')->assertOk()->assertJsonPath('result.type', 'in');
        $this->assertTrue(AttendanceDay::allCompanies()->where('employee_id', $emp->id)->where('date', '2026-10-12')->whereNotNull('in_at')->exists());

        // 8) إجازة طارئة: مستويات الاعتماد بالترتيب، وإشعار عند كل قرار
        $sub = Employee::allCompanies()->where('department_id', $emp->department_id)->where('id', '!=', $emp->id)->where('status', '!=', 'terminated')->value('id');
        $l = $this->postJson('/api/me/leave', ['type' => 'emergency', 'from' => '2026-10-13', 'to' => '2026-10-13', 'reason' => 'ظرف عائلي طارئ', 'substitute_id' => $sub])->assertCreated()->json('data');
        $levels = count($l['steps']);
        $this->assertGreaterThanOrEqual(1, $levels);
        $me = User::where('email', 'rana.sayed@mail.sy')->value('id');
        $before = Notice::allCompanies()->where('user_id', $me)->count();
        for ($i = 0; $i < 6 && LeaveRequest::find($l['id'])->status !== 'approved'; $i++) {
            $step = \App\Models\RequestStep::where('subject_id', $l['id'])->where('status', 'pending')->firstOrFail();
            $owner = $step->user_id ? User::find($step->user_id)->email : 'hr@alfarada.sy';
            $this->login($owner);
            $this->postJson("/api/me/approvals/{$step->id}", ['decision' => 'approve', 'note' => 'موافق'])->assertOk();
        }
        $this->assertSame('approved', LeaveRequest::find($l['id'])->status);
        $this->assertGreaterThanOrEqual(1, Notice::allCompanies()->where('user_id', $me)->count() - $before);
        $this->assertTrue(Notice::allCompanies()->where('user_id', $me)->where('text_ar', 'like', 'تمت الموافقة النهائية%')->exists());

        // 9) «المساعد» يجيب الموظفة من بياناتها هي
        $this->login('rana.sayed@mail.sy', 'Rana2026pw');
        $events = array_values(array_filter(array_map(fn ($x) => json_decode($x, true), explode("\n", $this->postJson('/api/assistant/ask', ['text' => 'كم رصيد إجازاتي؟'])->assertOk()->streamedContent()))));
        $this->assertSame('balance', collect($events)->firstWhere('t', 'done')['message']['intent']);

        // 10) سجل التدقيق: من، ماذا، متى، قبل/بعد
        $this->assertTrue(AuditLog::where('action', 'appointment.approved')->count() >= 4);
        $this->assertTrue(AuditLog::where('subject_type', (new Employee)->getMorphClass())->where('subject_id', $emp->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'request.approved')->where('subject_id', $l['id'])->exists());
    }

    /** §9.4 (v3): شاغر من أتمتة وشاغر من عميل يظهران معاً على البوابة — للزائر ولمستخدم شركة مسجّل الدخول (كان يفشل للمسجّل) */
    public function test_public_portal_lists_every_companys_jobs_for_guests_and_signed_in_users(): void
    {
        $slugs = fn () => collect($this->getJson('/api/public/jobs')->assertOk()->json('data'))->pluck('company.slug')->unique()->sort()->values()->all();
        $guest = $slugs();
        $this->assertContains('atmata', $guest);
        $this->assertContains('alfarada', $guest);
        $this->login('hr@alfarada.sy');
        $this->assertSame($guest, $slugs());
        $this->getJson('/api/public/portal')->assertOk();
        $this->login('samer.halabi@alfarada.sy');
        $this->assertSame($guest, $slugs());
    }
}
