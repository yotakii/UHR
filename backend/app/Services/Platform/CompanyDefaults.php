<?php

namespace App\Services\Platform;

use App\Models\ApprovalChain;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\Competency;
use App\Models\MessageTemplate;
use App\Models\NotificationChannel;
use App\Models\PerformanceCycle;
use App\Models\Policy;
use App\Models\Setting;
use App\Support\Settings;
use App\Support\Tenant;

/**
 * القيم الافتراضية لكل شركة جديدة: الإعدادات، السياسات، فئات الأصول، الكفاءات، سلاسل الاعتماد،
 * قنوات الإشعار وقوالبها، دورة الأداء الحالية. آمن للتكرار.
 */
class CompanyDefaults
{
    public static function seed(Company $company): void
    {
        Tenant::run($company, fn () => self::fill($company));
    }

    private static function fill(Company $company): void
    {
        foreach (Settings::DEFAULTS as $key => $value) if (! Setting::where('company_id', $company->id)->where('key', $key)->exists()) Settings::put($key, $value);
        Settings::put('company', [...Settings::DEFAULTS['company'], 'name_ar' => $company->name_ar, 'name_en' => $company->name_en,
            'short_ar' => $company->short_ar ?: $company->name_ar, 'short_en' => $company->short_en ?: $company->name_en, 'brand_latin' => $company->brand_latin ?: mb_strtoupper($company->name_en)]);
        \App\Support\Forms::seed();
        foreach (Settings::POLICY_DEFAULTS as $key => $value) Policy::firstOrCreate(['key' => $key, 'effective_from' => '2020-01-01'], ['value' => $value]);

        $cats = ['computers' => ['حواسب', 'Computers'], 'phones' => ['هواتف', 'Phones'], 'cards' => ['بطاقات', 'Cards'], 'vehicles' => ['مركبات', 'Vehicles'], 'peripherals' => ['ملحقات', 'Peripherals']];
        foreach ($cats as $code => [$ar, $en]) AssetCategory::updateOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en]);

        $comp = [['جودة العمل', 'Quality'], ['الالتزام', 'Reliability'], ['العمل الجماعي', 'Teamwork'], ['المبادرة', 'Initiative'], ['التواصل', 'Communication'], ['المعرفة الفنية', 'Expertise']];
        foreach ($comp as $i => [$ar, $en]) Competency::updateOrCreate(['name_en' => $en], ['name_ar' => $ar, 'sort' => $i + 1]);

        $mgr = ['role_ar' => 'المدير المباشر', 'role_en' => 'Direct manager', 'role_key' => 'manager'];
        $hr = ['role_ar' => 'الموارد البشرية', 'role_en' => 'Human Resources', 'role_key' => 'hr_director'];
        ApprovalChain::firstOrCreate(['type' => 'leave'], ['levels' => [$mgr, $hr]]);
        ApprovalChain::firstOrCreate(['type' => 'resignation'], ['levels' => [$mgr, $hr]]);
        // المسار الموحد (SRS §10.1): التبرير المدير ← HR، والتكليف بالإضافي (يطلبه المدير) ← HR
        ApprovalChain::firstOrCreate(['type' => 'justification'], ['levels' => [$mgr, $hr]]);
        ApprovalChain::firstOrCreate(['type' => 'overtime'], ['levels' => [$hr]]);
        // طلبات الخدمات والشهادات والسلفة (D-16، D-01) ← الموارد البشرية
        ApprovalChain::firstOrCreate(['type' => 'service'], ['levels' => [$hr]]);
        ApprovalChain::firstOrCreate(['type' => 'advance'], ['levels' => [$hr]]);
        // M17: الجزاءات الأشد (حسم فوق صلاحية HR، الترفيع، الفصل) ← المدير العام
        ApprovalChain::firstOrCreate(['type' => 'penalty'], ['levels' => [['role_ar' => 'المدير العام', 'role_en' => 'General manager', 'role_key' => 'ceo']]]);
        \App\Support\ViolationCatalog::seed();
        ApprovalChain::firstOrCreate(['type' => 'appointment'], ['levels' => [
            $hr + ['user_id' => null],
            ['role_ar' => 'الإدارة المالية', 'role_en' => 'Finance', 'role_key' => 'finance', 'user_id' => null],
            ['role_ar' => 'المدير التنفيذي', 'role_en' => 'Executive Director', 'role_key' => 'executive', 'user_id' => null],
            ['role_ar' => 'الرئيس التنفيذي', 'role_en' => 'CEO', 'role_key' => 'ceo', 'user_id' => null],
        ]]);

        foreach (['email', 'sms', 'whatsapp'] as $ch) NotificationChannel::firstOrCreate(['key' => $ch], ['enabled' => $ch === 'email']);
        $tpl = [
            'application_received' => ['استلمنا طلبك على وظيفة :job. رقم المتابعة: :ref', 'We received your application for :job. Reference: :ref'],
            'interview_invite' => ['ندعوك لمقابلة على وظيفة :job. سنتواصل معك لتأكيد الموعد.', 'You are invited to interview for :job. We will contact you to confirm the time.'],
            'offer_sent' => ['صدر لك عرض عمل على وظيفة :job — ادخل حسابك للرد.', 'A job offer for :job is waiting in your account.'],
            'otp' => ['رمز التحقق لعرض الرواتب: :code — صالح دقيقتين.', 'Your pay verification code: :code — valid for 2 minutes.'],
        ];
        foreach ($tpl as $key => [$ar, $en]) foreach (['email', 'sms', 'whatsapp'] as $ch) MessageTemplate::firstOrCreate(['key' => $key, 'channel' => $ch], ['body_ar' => $ar, 'body_en' => $en]);

        $y = now()->year; $q = (int) ceil(now()->month / 3);
        PerformanceCycle::firstOrCreate(['name_en' => "Q{$q} {$y} review cycle"], [
            'name_ar' => "دورة تقييم الربع ".['', 'الأول', 'الثاني', 'الثالث', 'الرابع'][$q]." {$y}",
            'starts_on' => now()->firstOfQuarter()->toDateString(), 'ends_on' => now()->lastOfQuarter()->toDateString(),
            'status' => 'open', 'weights' => ['manager' => 0.5, 'peers' => 0.3, 'self' => 0.2],
        ]);
    }
}
