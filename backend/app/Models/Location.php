<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;

/** مدينة/فرع: المدن المشتركة على المنصة (company_id = null) + فروع الشركة الخاصة، ولكل منها منطقة زمنية (ملاحظة الاختبار 80) */
class Location extends Model
{
    protected function casts(): array { return ['active' => 'boolean']; }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            if ($id = Tenant::id()) $q->where(fn ($w) => $w->whereNull($q->qualifyColumn('company_id'))->orWhere($q->qualifyColumn('company_id'), $id));
        });
    }
}
