<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** رمز في قائمة مرجعية: الرمز ثابت بعد أول استخدام، والاسم يتغير؛ لا حذف — تعطيل فقط */
class CodeItem extends Model
{
    protected function casts(): array { return ['attrs' => 'array', 'valid_from' => 'date', 'valid_to' => 'date', 'used_at' => 'datetime']; }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            if ($id = Tenant::id()) $q->where(fn ($w) => $w->whereNull($q->qualifyColumn('company_id'))->orWhere($q->qualifyColumn('company_id'), $id));
        });
        static::creating(function (CodeItem $i) { if (! $i->company_id && Tenant::id() && ! CodeList::withoutGlobalScopes()->whereKey($i->code_list_id)->value('system')) $i->company_id = Tenant::id(); });
    }

    public function list(): BelongsTo { return $this->belongsTo(CodeList::class, 'code_list_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(CodeItem::class, 'parent_id'); }

    /** فعّال في تاريخ: الحالة active وضمن تاريخي السريان */
    public function scopeLive(Builder $q, ?string $on = null): Builder
    {
        $on ??= now()->toDateString();
        return $q->where('status', 'active')->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $on))->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $on));
    }

    public function attr(string $key, mixed $default = null): mixed { return ($this->attrs ?? [])[$key] ?? $default; }
}
