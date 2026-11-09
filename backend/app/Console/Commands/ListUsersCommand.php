<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * لمدير الخادم: الحسابات الفعّالة (البريد، الاسم، الشركة، الأدوار، آخر دخول). كلمات المرور لا تُعرض أبداً — هي مخزّنة مشفّرة.
 *   php artisan uhr:users                       ← جدول
 *   php artisan uhr:users --company=uniqueness  ← شركة واحدة
 *   php artisan uhr:users --csv > users.csv     ← ملف
 *   php artisan uhr:users --reset-test          ← (بيئة التجربة فقط) يعيد كلمة مرور كل الحسابات المعروضة إلى 123456 ويفك القفل
 */
class ListUsersCommand extends Command
{
    protected $signature = 'uhr:users {--company= : رابط الشركة (slug)} {--csv : إخراج CSV} {--reset-test : إعادة كلمة المرور إلى 123456 (ليس في الإنتاج)}';
    protected $description = 'List active accounts (and optionally reset test passwords on staging)';

    public function handle(): int
    {
        $q = User::with(['company', 'roles', 'employee'])->where('active', true)->orderByRaw('company_id nulls first')->orderBy('id');
        if ($slug = $this->option('company')) $q->whereHas('company', fn ($c) => $c->where('slug', $slug));
        $users = $q->get();

        if ($this->option('reset-test')) {
            if (app()->environment('production')) { $this->error('Refused in production.'); return self::FAILURE; }
            foreach ($users as $u) $u->forceFill(['password' => '123456', 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null, 'activated_at' => $u->activated_at ?? now(), 'invite_hash' => null])->save();
            Audit::record('users.test_passwords_reset', null, null, ['count' => $users->count(), 'company' => $slug]);
            $this->info("Password set to 123456 for {$users->count()} accounts.");
        }

        $rows = $users->map(fn (User $u) => [$u->email, $u->displayName('ar'), $u->company?->slug ?? ($u->hasRole('platform_admin') ? 'platform' : 'applicant'),
            $u->roles->pluck('key')->implode(' '), $u->employee?->code ?? '', $u->last_login_at?->format('Y-m-d H:i') ?? '', $u->locked_until?->isFuture() ? 'locked' : ''])->all();
        $head = ['email', 'name', 'company', 'roles', 'code', 'last_login', 'state'];
        if ($this->option('csv')) {
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, $head);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        } else {
            $this->table($head, $rows);
            $this->line(count($rows).' active accounts. Passwords are never shown (stored encrypted).');
        }
        return self::SUCCESS;
    }
}
