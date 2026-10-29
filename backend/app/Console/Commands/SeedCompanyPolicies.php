<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Policies\PolicyLibrary;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * مكتبة السياسات (D-26): يبذر وثائق الشركة الحالية (نظام العاملين، HR.01-01، HR.01-03، جدول المخالفات، ميثاق السلوك) منشورةً.
 *   php artisan policies:seed-company alfarada --by=hr@alfarada.sy [--notify]
 */
class SeedCompanyPolicies extends Command
{
    protected $signature = 'policies:seed-company {company : slug الشركة} {--by= : بريد المستخدم الذي تُنسب إليه} {--notify : إشعار الموظفين}';
    protected $description = 'Seed the policy library with the company documents';

    public function handle(PolicyLibrary $lib): int
    {
        $c = Company::where('slug', $this->argument('company'))->first();
        if (! $c) { $this->error('Unknown company'); return self::FAILURE; }
        $by = $this->option('by') ? User::where('company_id', $c->id)->where('email', strtolower($this->option('by')))->first() : User::where('company_id', $c->id)->whereHas('roles', fn ($q) => $q->whereIn('key', ['hr_director', 'admin']))->orderBy('id')->first();
        if (! $by) { $this->error('No user to attribute the documents to (--by)'); return self::FAILURE; }
        $n = Tenant::run($c->id, fn () => $lib->seedCompanyDocs($by, (bool) $this->option('notify')));
        $this->info("Added {$n} document(s).");
        return self::SUCCESS;
    }
}
