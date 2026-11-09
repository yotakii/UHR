<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * بيئة التجربة فقط: يجهّز حسابات شركة للاختبار.
 *  - كل حساب في الشركة: البريد ← {الجزء قبل @}@{النطاق}، كلمة المرور الموحدة، فك القفل والتفعيل (الأسماء والأدوار كما هي)
 *  - حساب لكل دور ليس له صاحب بعد (ceo@، finance@، executive@، hr.officer@، recruiter@ …)
 *  - حسابات المتقدمين والمنصة لا تُمس.
 *   php artisan uhr:test-accounts uniqueness --dry-run
 *   php artisan uhr:test-accounts uniqueness
 */
class TestAccountsCommand extends Command
{
    protected $signature = 'uhr:test-accounts {company : رابط الشركة (slug)} {--domain= : النطاق (الافتراضي {slug}.sy)} {--password=123456} {--dry-run : عرض التغييرات دون تنفيذ}';
    protected $description = 'Staging only: company test accounts on one domain with one password, plus one account per missing role';

    /** حساب لكل دور ناقص: البريد والاسم */
    private const ROLE_ACCOUNTS = [
        'admin' => ['admin', 'مدير النظام', 'System administrator'],
        'hr_director' => ['hr', 'مدير الموارد البشرية', 'HR director'],
        'hr_officer' => ['hr.officer', 'موظف الموارد البشرية', 'HR officer'],
        'recruiter' => ['recruiter', 'مسؤول التوظيف', 'Recruiter'],
        'finance' => ['finance', 'الإدارة المالية', 'Finance'],
        'executive' => ['executive', 'المدير التنفيذي', 'Executive'],
        'ceo' => ['ceo', 'المدير العام', 'General manager'],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) { $this->error('Refused in production.'); return self::FAILURE; }
        $co = Company::where('slug', $this->argument('company'))->first();
        if (! $co) { $this->error('No company with this slug'); return self::FAILURE; }
        $domain = strtolower(ltrim($this->option('domain') ?: $co->slug.'.sy', '@'));
        $pw = (string) $this->option('password');
        $dry = (bool) $this->option('dry-run');
        $taken = User::pluck('email')->map(fn ($e) => strtolower($e))->flip()->all();
        $plan = [];

        $users = User::with(['roles', 'employee'])->where('company_id', $co->id)->orderBy('id')->get();
        foreach ($users as $u) {
            $email = strtolower($u->email);
            if (! str_ends_with($email, '@'.$domain)) {
                $local = preg_replace('/[^a-z0-9._-]/', '', strstr($email, '@', true)) ?: 'user'.$u->id;
                $new = $local.'@'.$domain; $i = 2;
                while (isset($taken[$new])) $new = $local.$i++.'@'.$domain;
                unset($taken[$email]); $taken[$new] = true;
                $email = $new;
            }
            $plan[] = ['user' => $u, 'email' => $email, 'roles' => $u->roles->pluck('key')->all(), 'new' => false];
        }
        $have = collect($plan)->pluck('roles')->flatten()->unique()->all();
        foreach (self::ROLE_ACCOUNTS as $role => [$local, $ar, $en]) {
            if (in_array($role, $have, true)) continue;
            $email = $local.'@'.$domain; $i = 2;
            while (isset($taken[$email])) $email = $local.$i++.'@'.$domain;
            $taken[$email] = true;
            $plan[] = ['user' => null, 'email' => $email, 'roles' => [$role], 'name' => [$ar, $en], 'new' => true];
        }

        $this->table(['', 'email', 'name', 'roles'], array_map(fn ($p) => [$p['new'] ? 'new' : ($p['user']->email === $p['email'] ? '' : 'renamed from '.$p['user']->email),
            $p['email'], $p['new'] ? $p['name'][0] : $p['user']->displayName('ar'), implode(' ', $p['roles'])], $plan));
        if ($dry) { $this->warn('Dry run — nothing changed.'); return self::SUCCESS; }

        DB::transaction(function () use ($plan, $co, $pw) {
            Tenant::run($co->id, function () use ($plan, $co, $pw) {
                foreach ($plan as $p) {
                    $fill = ['password' => $pw, 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null, 'active' => true,
                        'activated_at' => now(), 'invite_hash' => null, 'invite_kind' => null, 'invite_expires_at' => null];
                    if ($p['new']) {
                        $u = User::create(['email' => $p['email'], 'name' => $p['name'][0], 'name_en' => $p['name'][1], 'company_id' => $co->id] + $fill);
                        $u->roles()->sync(Role::whereIn('key', $p['roles'])->pluck('id'));
                    } else {
                        $u = $p['user'];
                        $u->forceFill(['email' => $p['email']] + $fill)->save();
                        if ($u->employee_id) Employee::whereKey($u->employee_id)->update(['email' => $p['email']]);
                    }
                }
            });
        });
        Audit::record('users.test_accounts', null, null, ['company' => $co->slug, 'count' => count($plan)]);
        $this->info(count($plan)." accounts ready. Password: {$pw}");
        return self::SUCCESS;
    }
}
