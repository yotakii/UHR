<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * قيم المنصة الأساسية (آمنة للإنتاج والتكرار): الأدوار، المدن، نصوص البوابة العامة.
 * قيم كل شركة تُبذر عند إنشائها: App\Services\Platform\CompanyDefaults.
 */
class DefaultsSeeder extends Seeder
{
    public function run(): void
    {
        Audit::mute(fn () => $this->seed());
    }

    private function seed(): void
    {
        $roles = [
            'admin' => ['مدير النظام', 'System administrator'], 'hr_director' => ['مدير الموارد البشرية', 'HR director'],
            'hr_officer' => ['موظف موارد بشرية', 'HR officer'], 'finance' => ['الإدارة المالية', 'Finance'],
            'executive' => ['المدير التنفيذي', 'Executive director'], 'ceo' => ['الرئيس التنفيذي', 'CEO'],
            'manager' => ['مدير مباشر', 'Line manager'], 'employee' => ['موظف', 'Employee'], 'candidate' => ['متقدم', 'Applicant'],
            'recruiter' => ['مسؤول توظيف', 'Recruiter'], 'platform_admin' => ['مدير المنصة', 'Platform administrator'],
        ];
        foreach ($roles as $key => [$ar, $en]) Role::updateOrCreate(['key' => $key], ['name_ar' => $ar, 'name_en' => $en]);

        $cities = ['dam' => ['دمشق', 'Damascus'], 'alp' => ['حلب', 'Aleppo'], 'hom' => ['حمص', 'Homs'], 'lat' => ['اللاذقية', 'Latakia'], 'tar' => ['طرطوس', 'Tartus'], 'ham' => ['حماة', 'Hama']];
        foreach ($cities as $code => [$ar, $en]) Location::updateOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en]);

        foreach (Settings::PLATFORM_DEFAULTS as $key => $value) if (! Setting::whereNull('company_id')->where('key', $key)->exists()) Settings::putPlatform($key, $value);
    }
}
