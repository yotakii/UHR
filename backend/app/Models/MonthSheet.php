<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** كشف الدوام والمستحقات الشهري (D-01): draft → calculated → approved → closed (لا تعديل بعده) */
class MonthSheet extends Model
{
    use Concerns\BelongsToCompany;

    public const STATUSES = ['draft', 'calculated', 'approved', 'closed'];

    protected function casts(): array { return ['totals' => 'array', 'calculated_at' => 'datetime', 'approved_at' => 'datetime', 'closed_at' => 'datetime']; }
    public function rows(): HasMany { return $this->hasMany(MonthSheetRow::class); }
    public function calculator(): BelongsTo { return $this->belongsTo(User::class, 'calculated_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
}
