<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عزل الشركات: فلترة تلقائية بالشركة الحالية، وتعبئة company_id عند الإنشاء */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            if ($id = Tenant::id()) $q->where($q->qualifyColumn('company_id'), $id);
        });
        static::creating(function ($m) {
            if (! $m->company_id && Tenant::id()) $m->company_id = Tenant::id();
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    /** تجاوز الفلترة صراحةً (مدير المنصة، البوابة العامة) */
    public static function allCompanies(): Builder { return static::withoutGlobalScope('company'); }
}
