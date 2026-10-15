<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Platform\CompanyProvisioner;
use App\Support\Audit;
use Database\Seeders\DefaultsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تثبيت المنصة: القيم الأساسية + حساب مدير المنصة، واختيارياً أول شركة بمديرها.
 *   php artisan uhr:install --email=platform@atmata.sy
 *   php artisan uhr:install --email=platform@atmata.sy --company=alfarada --company-name-ar="الفرادة" --company-name-en="Uniqueness" --company-admin=admin@alfarada.sy
 * بدون --password تُولَّد كلمة مرور مؤقتة ويُلزم صاحبها بتغييرها عند أول دخول.
 */
class InstallCommand extends Command
{
    protected $signature = 'uhr:install {--email= : بريد مدير المنصة} {--password= : كلمة المرور (اختياري)} {--name=مدير المنصة}
        {--company= : slug أول شركة (اختياري)} {--company-name-ar=} {--company-name-en=} {--company-admin= : بريد مدير الشركة}';
    protected $description = 'Install UHR platform defaults, the platform administrator and optionally the first company';

    public function handle(CompanyProvisioner $prov): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Platform administrator email'))));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Invalid email'); return self::FAILURE; }

        DB::transaction(function () use ($email, $prov) {
            (new DefaultsSeeder)->run();
            $password = $this->option('password') ?: Str::password(14, symbols: false);
            $user = User::firstOrNew(['email' => $email]);
            $user->fill(['name' => $this->option('name'), 'company_id' => null, 'password' => $password, 'must_change_password' => ! $this->option('password'), 'active' => true])->save();
            $user->roles()->syncWithoutDetaching(Role::where('key', 'platform_admin')->pluck('id'));
            Audit::record('system.installed', $user, null, ['email' => $email]);
            $this->info("UHR installed. Platform admin: {$email}");
            if (! $this->option('password')) $this->warn("Temporary password: {$password}  (must be changed at first sign-in)");

            if ($slug = $this->option('company')) {
                $admin = strtolower(trim((string) $this->option('company-admin')));
                [$company, $temp] = $prov->create(['slug' => $slug, 'name_ar' => $this->option('company-name-ar') ?: $slug, 'name_en' => $this->option('company-name-en') ?: $slug, 'status' => 'active'], $admin ?: null);
                $this->info("Company created: {$company->slug}".($admin ? " — admin {$admin}" : ''));
                if ($temp) $this->warn("Company admin temporary password: {$temp}");
            }
        });
        return self::SUCCESS;
    }
}
