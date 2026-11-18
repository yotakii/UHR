<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Engagement;
use App\Models\Employee;
use App\Models\External;
use App\Models\MonthSheetRow;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\VolunteerLog;
use App\Services\Codes\Codes;
use App\Support\Tenant;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** الدفعة 22: غير الموظفين — دورة المتطوع، فحص طبيعة العلاقة، الحدود، الشهادة، قائمة الخبراء، الدفع مقابل المخرجات، سرية الأتعاب */
class ExternalsTest extends TestCase
{
    use RefreshDatabase;

    private static function sig(): string
    {
        $img = imagecreatetruecolor(240, 80); imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 255, 255, 255));
        ob_start(); imagepng($img); return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function login(string $email): void { $this->postJson('/api/auth/logout'); $this->postJson('/api/auth/login', ['email' => $email, 'password' => '123456'])->assertOk(); }
    private function cat(string $code): int { return Tenant::run(\App\Models\Company::where('slug', 'uniqueness')->first(), fn () => Codes::resolve('worker_categories', $code)->id); }

    public function test_volunteer_cycle_relation_check_limits_and_certificate(): void
    {
        $this->login('hr@uniqueness.sy');
        $sup = Employee::where('email', 'samer.halabi@uniqueness.sy')->first();
        // فئة تدخل الرواتب مكانها الموظفون
        $this->postJson('/api/hr/externals', ['worker_category_id' => $this->cat('TMP'), 'name' => 'مؤقت'])->assertStatus(422)->assertJsonPath('code', 'category_in_payroll');
        $id = $this->postJson('/api/hr/externals', ['worker_category_id' => $this->cat('VOL'), 'name' => 'ريم متطوعة', 'email' => 'reem@vol.sy', 'phone' => '0933', 'national_id' => '010101'])->assertCreated()->json('id');
        $x = External::allCompanies()->find($id);
        $this->assertSame(['volunteer', 'accepted'], [$x->kind, $x->status]);

        $base = ['title' => 'دعم فعالية التوظيف', 'tasks' => 'استقبال الزوار', 'supervisor_id' => $sup->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-12-31', 'weekly_hours' => 10, 'relation' => 'alongside'];
        // حدود المدة والساعات، و«بديل عن موظف» يحتاج مبرراً ومدة قصيرة
        $this->postJson("/api/hr/externals/{$id}/engagements", ['ends_on' => '2027-08-01'] + $base)->assertStatus(422)->assertJsonPath('code', 'too_long');
        $this->postJson("/api/hr/externals/{$id}/engagements", ['weekly_hours' => 30] + $base)->assertStatus(422)->assertJsonPath('code', 'too_many_hours');
        $this->postJson("/api/hr/externals/{$id}/engagements", ['relation' => 'replaces', 'replaces_position' => 'موظف استقبال'] + $base)->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/hr/externals/{$id}/engagements", ['fee_model' => 'monthly', 'amount' => 100] + $base)->assertStatus(422)->assertJsonPath('code', 'unpaid');

        // AC-VL-02: «يحل محل موظف» ← مبرر + مدة قصيرة ← اعتماد الإدارة العليا
        $g = $this->postJson("/api/hr/externals/{$id}/engagements", ['relation' => 'replaces', 'replaces_position' => 'موظف استقبال', 'justification' => 'تغطية إجازة أمومة لموظفة الاستقبال',
            'ends_on' => '2026-12-31', 'fee_model' => 'expenses', 'relation_test' => ['fixed_hours' => true]] + $base)->assertCreated()->json('data');
        $this->assertTrue($g['high']);
        $sent = $this->postJson("/api/hr/externals/engagements/{$g['id']}/submit")->assertOk()->json('data');
        $this->assertCount(2, $sent['steps']);
        // لا تُسجَّل ساعات قبل التوقيع
        $this->postJson("/api/hr/externals/engagements/{$g['id']}/logs", ['date' => '2026-10-12', 'hours' => 3, 'activity' => 'استقبال'])->assertStatus(409)->assertJsonPath('code', 'not_active');
        // مدير الموارد البشرية (غير مقدّم الطلب) ثم الرئيس التنفيذي
        $this->postJson("/api/hr/externals/engagements/{$g['id']}/decide", ['approve' => true])->assertForbidden();
        $this->login('nour.hassan@uniqueness.sy');
        $this->postJson("/api/hr/externals/engagements/{$g['id']}/decide", ['approve' => true])->assertOk();
        $ceo = User::where('company_id', $sup->company_id)->whereHas('roles', fn ($r) => $r->where('key', 'ceo'))->first();
        $this->actingAs($ceo);
        $this->postJson("/api/hr/externals/engagements/{$g['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $x->refresh();
        $this->assertSame('pending_sign', $x->status);
        $this->assertNotNull($x->user_id);
        $vol = User::find($x->user_id);
        $this->assertSame('external', $vol->portal());

        // بوابة المتطوع: التوقيع، الساعات، الحد الأسبوعي، التعويض، لا وصول للموظفين
        $this->actingAs($vol);
        $home = $this->getJson('/api/ext')->assertOk();
        $this->assertSame('approved', $home->json('engagement.status'));
        $this->postJson("/api/ext/engagements/{$g['id']}/sign", ['pledges' => ['agreement'], 'signature' => self::sig()])->assertStatus(422)->assertJsonPath('code', 'pledges_required');
        $this->postJson("/api/ext/engagements/{$g['id']}/sign", ['pledges' => ['agreement', 'confidentiality', 'conduct', 'safety'], 'signature' => self::sig()])->assertOk()->assertJsonPath('engagement.status', 'active');
        $this->postJson("/api/ext/engagements/{$g['id']}/logs", ['date' => '2026-10-12', 'hours' => 4, 'activity' => 'استقبال الزوار'])->assertCreated();
        $w = $this->postJson("/api/ext/engagements/{$g['id']}/logs", ['date' => '2026-10-12', 'hours' => 4.5, 'activity' => 'ترتيب القاعة'])->assertCreated()->json('warning');
        $this->assertNotNull($w);
        $this->postJson("/api/ext/engagements/{$g['id']}/logs", ['date' => '2026-10-12', 'hours' => 2, 'activity' => 'إضافي'])->assertStatus(422)->assertJsonPath('code', 'weekly_limit');
        $this->postJson("/api/ext/engagements/{$g['id']}/logs", ['date' => '2026-10-13', 'hours' => 1, 'activity' => 'غداً'])->assertStatus(422)->assertJsonPath('code', 'future');
        $this->getJson('/api/hr/employees')->assertForbidden();
        $this->getJson('/api/hr/externals')->assertForbidden();
        $file = $this->postJson('/api/ext/files', ['file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')])->assertCreated()->json('id');
        $this->postJson("/api/ext/engagements/{$g['id']}/expense", ['amount' => 999999, 'description' => 'نقل', 'file_id' => $file])->assertStatus(422)->assertJsonPath('code', 'over_cap');
        $this->postJson("/api/ext/engagements/{$g['id']}/expense", ['amount' => 15000, 'description' => 'نقل', 'file_id' => $file])->assertOk();

        // المشرف يصادق الساعات وينهي التكليف ← شهادة برمز تحقق وتعطيل الحساب
        $this->login('samer.halabi@uniqueness.sy');
        $logs = VolunteerLog::allCompanies()->where('engagement_id', $g['id'])->pluck('id')->all();
        $this->getJson('/api/hr/externals/logs')->assertForbidden();   // المشرف موظف — المصادقة من بوابته
        $this->assertCount(2, $this->getJson('/api/me/volunteer-hours')->assertOk()->json('data'));
        $this->postJson('/api/me/volunteer-hours', ['ids' => $logs, 'approve' => true])->assertOk()->assertJsonPath('count', 2);
        $this->login('hr@uniqueness.sy');
        $end = $this->postJson("/api/hr/externals/engagements/{$g['id']}/end", ['rating' => 5, 'note' => 'ملتزمة'])->assertOk()->json('data');
        $this->assertSame('ended', $end['status']);
        $this->assertFalse((bool) User::find($x->user_id)->active);
        $v = $this->getJson('/api/public/verify/'.$end['verify_code'])->assertOk();
        $this->assertSame(8.5, (float) $v->json('data.hours'));
        $this->getJson('/api/public/verify/NOPE12345')->assertNotFound();

        // AC-VL-01: لا يظهر في الموظفين ولا في كشف الرواتب
        $this->assertFalse(Employee::where('email', 'reem@vol.sy')->exists());
        $this->assertFalse(MonthSheetRow::whereHas('employee', fn ($q) => $q->where('email', 'reem@vol.sy'))->exists());
        $rep = $this->getJson('/api/hr/externals/reports')->assertOk();
        $this->assertSame(8.5, (float) $rep->json('data.volunteers.hours'));
        // طلب التعويض للمالية خارج الرواتب
        $this->assertSame(1, PaymentRequest::allCompanies()->where('source_type', 'expense')->count());
    }

    public function test_roster_search_assignment_deliverables_payments_and_fee_secrecy(): void
    {
        $this->login('hr@uniqueness.sy');
        $spec = Tenant::run(\App\Models\Company::where('slug', 'uniqueness')->first(), fn () => [Codes::resolve('specialties', 'HRM')->id, Codes::resolve('specialties', 'HRM-01')->id, Codes::resolve('specialties', 'TEC-01')->id]);
        // التسجيل العام ← متقدم
        $this->postJson('/api/public/experts/uniqueness', ['name' => 'د. هالة', 'email' => 'hala@exp.sy', 'phone' => '0944', 'specialties' => [$spec[1]], 'years' => 12, 'level' => 'expert',
            'country' => 'SY', 'city' => 'دمشق', 'cv' => UploadedFile::fake()->create('cv.pdf', 30, 'application/pdf'), 'consent' => true])->assertCreated();
        $hala = External::allCompanies()->where('email', 'hala@exp.sy')->first();
        $this->assertSame(['expert', 'applicant'], [$hala->kind, $hala->status]);
        $b = $this->postJson('/api/hr/externals', ['worker_category_id' => $this->cat('EXP'), 'name' => 'م. باسل', 'email' => 'basel@exp.sy', 'specialties' => [$spec[1]], 'years' => 15, 'level' => 'senior',
            'country' => 'SY', 'city' => 'دمشق', 'available' => 'now', 'rate' => 150, 'rate_unit' => 'day', 'rate_currency' => 'USD', 'documents' => [['type' => 'cert', 'file_id' => $hala->cv_file_id]]])->assertCreated()->json('id');
        $t = $this->postJson('/api/hr/externals', ['worker_category_id' => $this->cat('EXP'), 'name' => 'أمن', 'email' => 'sec@exp.sy', 'specialties' => [$spec[2]], 'years' => 20, 'level' => 'expert', 'cv_file_id' => $hala->cv_file_id])->assertCreated()->json('id');
        $this->postJson("/api/hr/externals/{$hala->id}/status", ['status' => 'approved'])->assertOk();
        $this->postJson("/api/hr/externals/{$b}/status", ['status' => 'approved'])->assertOk();
        $this->postJson("/api/hr/externals/{$t}/status", ['status' => 'approved'])->assertOk();

        // AC-RS-01: تخصص رئيسي يشمل فروعه، 10 سنوات فأكثر، دمشق، متاح ← اثنان
        $res = $this->getJson("/api/hr/externals?tab=experts&specialty={$spec[0]}&years=10&city=دمشق&available=1")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$hala->id, $b], array_column($res, 'id'));

        // التكليف: شروط مرجعية ← قائمة مختصرة ← اختيار بمبرر ← عقد بمخرجات ودفعاتها
        $a = $this->postJson('/api/hr/externals/assignments', ['title' => 'تقييم الوظائف', 'tor' => ['objective' => 'بناء نظام تقييم وظائف للشركة', 'outputs' => 'دليل التقييم', 'specialties' => [$spec[1]]],
            'starts_on' => '2026-10-15', 'ends_on' => '2026-12-15', 'budget' => 3000, 'currency' => 'USD'])->assertCreated()->json('id');
        $show = $this->getJson("/api/hr/externals/assignments/{$a}")->assertOk();
        $this->assertContains($b, array_column($show->json('data.suggested'), 'id'));
        $this->postJson("/api/hr/externals/assignments/{$a}/select", ['external_id' => $b, 'reason' => 'خبرة أطول في تقييم الوظائف'])->assertStatus(409)->assertJsonPath('code', 'no_shortlist');
        $this->postJson("/api/hr/externals/assignments/{$a}/shortlist", ['items' => [['external_id' => $b], ['external_id' => $hala->id]]])->assertOk();
        $this->postJson("/api/hr/externals/assignments/{$a}/select", ['external_id' => $t, 'reason' => 'خبرة أطول في تقييم الوظائف'])->assertStatus(422)->assertJsonPath('code', 'not_shortlisted');
        $this->postJson("/api/hr/externals/assignments/{$a}/select", ['external_id' => $b, 'reason' => 'خبرة أطول في تقييم الوظائف'])->assertOk()->assertJsonPath('data.status', 'selected');
        $dl = [['title' => 'المنهجية', 'due_on' => '2026-10-30', 'payment' => 1000], ['title' => 'التقييم', 'due_on' => '2026-11-30', 'payment' => 1500], ['title' => 'الدليل', 'due_on' => '2026-12-15', 'payment' => 500]];
        $this->postJson("/api/hr/externals/assignments/{$a}/contract", ['total_fee' => 2500, 'deliverables' => $dl])->assertStatus(422)->assertJsonPath('code', 'mismatch');
        $this->postJson("/api/hr/externals/assignments/{$a}/contract", ['total_fee' => 3000, 'currency' => 'USD', 'tax_treatment' => 'يحدده المحاسب', 'deliverables' => $dl])->assertOk()->assertJsonPath('data.status', 'contracted');
        $basel = User::where('email', 'basel@exp.sy')->first();
        $this->assertSame('external', $basel->portal());

        // الخبير: التعهدان ثم تسليم المخرج الثاني
        $this->actingAs($basel);
        $this->postJson("/api/ext/assignments/{$a}/pledge", ['kinds' => ['confidentiality']])->assertStatus(422);
        $this->postJson("/api/ext/assignments/{$a}/pledge", ['kinds' => ['confidentiality', 'conflict']])->assertOk();
        $f = $this->postJson('/api/ext/files', ['file' => UploadedFile::fake()->create('eval.pdf', 40, 'application/pdf')])->assertCreated()->json('id');
        $second = Assignment::allCompanies()->find($a)->deliverables()->get()[1];
        $this->postJson("/api/ext/deliverables/{$second->id}", ['file_id' => $f])->assertOk();

        // AC-RS-02: اعتماد المخرج الثاني ← طلب دفع بقيمة دفعته، لا بند راتب
        $this->login('hr@uniqueness.sy');
        $this->postJson("/api/hr/externals/deliverables/{$second->id}/review", ['approve' => true])->assertOk();
        $p = PaymentRequest::allCompanies()->where('source_type', 'deliverable')->first();
        $this->assertSame(1500.0, $p->amount);
        $this->assertFalse(\App\Models\PayLine::allCompanies()->exists());
        // المالية تصرف برقم سند
        $this->login('admin@uniqueness.sy');
        $this->postJson("/api/hr/externals/payments/{$p->id}", ['action' => 'pay'])->assertStatus(422);
        $this->postJson("/api/hr/externals/payments/{$p->id}", ['action' => 'pay', 'finance_ref' => 'SV-77'])->assertOk()->assertJsonPath('data.status', 'paid');

        // AC-RS-03: المدير المباشر لا يرى الأتعاب ولا الملاحظات السرية
        $this->login('nour.hassan@uniqueness.sy');   // موظفة موارد بشرية خارج أدوار الأتعاب
        $row = collect($this->getJson('/api/hr/externals?tab=experts')->assertOk()->json('data'))->firstWhere('id', $b);
        $this->assertNull($row['rate']); $this->assertTrue($row['fees_hidden']);
        $this->login('hr@uniqueness.sy');
        $this->assertSame(150.0, (float) collect($this->getJson('/api/hr/externals?tab=experts')->json('data'))->firstWhere('id', $b)['rate']);

        // التقييم يُغلق التكليف ويحدّث المتوسط
        $this->postJson("/api/hr/externals/assignments/{$a}/rate", ['quality' => 5, 'timeliness' => 4, 'communication' => 5, 'value' => 4])->assertStatus(409)->assertJsonPath('code', 'pending_deliverables');
        $this->postJson("/api/hr/externals/assignments/{$a}/rate", ['quality' => 5, 'timeliness' => 4, 'communication' => 5, 'value' => 4, 'early' => true, 'notes' => 'ممتاز'])->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->assertSame(4.5, External::allCompanies()->find($b)->avg_rating);
        $this->assertSame('approved', External::allCompanies()->find($b)->status);
    }
}
