<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\Invites;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * إنشاء شركة على المنصة (مدير المنصة): السجل + قيمها الافتراضية + حساب مديرها الأول بكلمة مرور مؤقتة.
 */
class CompanyProvisioner
{
    /** @return array{0: Company, 1: ?string} الشركة ورابط تفعيل حساب مديرها (null إن أُعطيت كلمة مرور) */
    public function create(array $data, ?string $adminEmail, ?UploadedFile $logo = null, ?string $password = null): array
    {
        [$company, $temp] = DB::transaction(function () use ($data, $adminEmail, $password) {
            $company = Company::create($data);
            CompanyDefaults::seed($company);
            $link = null;
            if ($adminEmail) {
                $admin = User::create(['email' => strtolower(trim($adminEmail)), 'password' => $password ?? Invites::unusable(), 'name' => 'مدير '.$company->name_ar, 'name_en' => $company->name_en.' admin',
                    'company_id' => $company->id, 'must_change_password' => false, 'active' => true, 'activated_at' => $password !== null ? now() : null, 'invited_at' => $password === null ? now() : null]);
                $admin->roles()->attach(Role::whereIn('key', ['admin', 'hr_director'])->pluck('id'));
                if ($password === null) $link = app(Invites::class)->issue($admin, 'company');
            }
            return [$company, $link];
        });
        if ($logo) $this->setLogo($company, $logo);
        return [$company, $temp];
    }

    public function setLogo(Company $company, UploadedFile|string $logo): void
    {
        $path = "logos/{$company->slug}.png";
        $logo instanceof UploadedFile ? Storage::disk('public')->putFileAs('logos', $logo, "{$company->slug}.png") : Storage::disk('public')->put($path, file_get_contents($logo));
        $company->update(['logo_path' => $path]);
    }
}
