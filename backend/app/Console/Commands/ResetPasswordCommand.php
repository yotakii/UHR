<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * لمدير الخادم: فك قفل حساب وإعادة تعيين كلمة مروره (حين يُقفل المدير الوحيد مثلاً).
 *   php artisan uhr:reset-password admin@alfarada.sy               ← كلمة مؤقتة تُطبع ويُلزم بتغييرها
 *   php artisan uhr:reset-password admin@alfarada.sy --password=…  ← كلمة محددة
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'uhr:reset-password {email} {--password= : كلمة المرور الجديدة (اختياري)}';
    protected $description = 'Unlock an account and reset its password';

    public function handle(): int
    {
        $u = User::where('email', strtolower(trim($this->argument('email'))))->first();
        if (! $u) { $this->error('No account with this email'); return self::FAILURE; }
        $pw = $this->option('password') ?: Str::password(12, symbols: false);
        $u->forceFill(['password' => $pw, 'must_change_password' => ! $this->option('password'), 'failed_logins' => 0, 'locked_until' => null, 'active' => true])->save();
        Audit::record('user.password_reset', $u, null, ['by' => 'console']);
        $this->info("Unlocked: {$u->email}");
        if (! $this->option('password')) $this->warn("Temporary password: {$pw}  (must be changed at first sign-in)");
        return self::SUCCESS;
    }
}
