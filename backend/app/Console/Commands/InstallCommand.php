<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\DefaultsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تثبيت النظام: القيم الافتراضية + حساب مدير النظام الأول.
 *   php artisan uhr:install --email=admin@company.sy [--password=...]
 * بدون --password تُولَّد كلمة مرور مؤقتة ويُلزم المدير بتغييرها عند أول دخول.
 */
class InstallCommand extends Command
{
    protected $signature = 'uhr:install {--email= : بريد مدير النظام} {--password= : كلمة المرور (اختياري)} {--name=مدير النظام}';
    protected $description = 'Install UHR defaults and create the first administrator';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Administrator email'))));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Invalid email'); return self::FAILURE; }

        DB::transaction(function () use ($email) {
            (new DefaultsSeeder)->run();
            $password = $this->option('password') ?: Str::password(14, symbols: false);
            $user = User::firstOrNew(['email' => $email]);
            $user->fill(['name' => $this->option('name'), 'password' => $password, 'must_change_password' => ! $this->option('password'), 'active' => true])->save();
            $user->roles()->syncWithoutDetaching(Role::where('key', 'admin')->pluck('id'));
            Audit::record('system.installed', $user, null, ['email' => $email]);
            $this->info("UHR installed. Admin: {$email}");
            if (! $this->option('password')) $this->warn("Temporary password: {$password}  (must be changed at first sign-in)");
        });

        return self::SUCCESS;
    }
}
