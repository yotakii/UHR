<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CompanyWelcome;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * إنشاء شركة على المنصة (مدير المنصة): السجل + قيمها الافتراضية + حساب مديرها الأول بكلمة مرور مؤقتة.
 */
class CompanyProvisioner
{
    /** @return array{0: Company, 1: ?string} الشركة وكلمة المرور المؤقتة للمدير */
    public function create(array $data, ?string $adminEmail, ?UploadedFile $logo = null, ?string $password = null): array
    {
        [$company, $temp] = DB::transaction(function () use ($data, $adminEmail, $password) {
            $company = Company::create($data);
            CompanyDefaults::seed($company);
            $temp = null;
            if ($adminEmail) {
                $temp = $password ?? Str::password(12, symbols: false);
                $admin = User::create(['email' => strtolower(trim($adminEmail)), 'password' => $temp, 'name' => 'مدير '.$company->name_ar, 'name_en' => $company->name_en.' admin',
                    'company_id' => $company->id, 'must_change_password' => $password === null, 'active' => true]);
                $admin->roles()->attach(Role::whereIn('key', ['admin', 'hr_director'])->pluck('id'));
            }
            return [$company, $temp];
        });
        if ($logo) $this->setLogo($company, $logo);
        if ($adminEmail && $password === null) User::where('email', strtolower(trim($adminEmail)))->first()?->notify(new CompanyWelcome($company->name_ar, $company->name_en, $temp));
        return [$company, $temp];
    }

    public function setLogo(Company $company, UploadedFile|string $logo): void
    {
        $path = "logos/{$company->slug}.png";
        $logo instanceof UploadedFile ? Storage::disk('public')->putFileAs('logos', $logo, "{$company->slug}.png") : Storage::disk('public')->put($path, file_get_contents($logo));
        $company->update(['logo_path' => $path]);
    }
}
