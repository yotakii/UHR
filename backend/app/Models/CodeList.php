<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** قائمة مرجعية في دليل الترميز: نظامية مشتركة (company_id = null) أو خاصة بالشركة */
class CodeList extends Model
{
    protected function casts(): array { return ['hierarchical' => 'boolean', 'system' => 'boolean', 'required' => 'boolean', 'active' => 'boolean', 'attrs_schema' => 'array']; }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            if ($id = Tenant::id()) $q->where(fn ($w) => $w->whereNull($q->qualifyColumn('company_id'))->orWhere($q->qualifyColumn('company_id'), $id));
        });
        static::creating(function (CodeList $l) { if (! $l->system && ! $l->company_id && Tenant::id()) $l->company_id = Tenant::id(); });
    }

    public function items(): HasMany { return $this->hasMany(CodeItem::class); }
}
