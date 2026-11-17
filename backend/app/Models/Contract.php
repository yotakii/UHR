<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** عقد عمل في سجل العقود (الجزء D): نوعه من دليل الترميز، ولا يُفعَّل قبل اكتمال التواقيع */
class Contract extends Model
{
    use Concerns\BelongsToCompany;

    public const LIVE = ['pending_signatures', 'signed', 'active'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'probation_ends_on' => 'date', 'signatures' => 'array', 'copies' => 'array', 'exception' => 'boolean',
            'signed_at' => 'datetime', 'activated_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function type(): BelongsTo { return $this->belongsTo(CodeItem::class, 'contract_type_id'); }
    public function previous(): BelongsTo { return $this->belongsTo(Contract::class, 'previous_id'); }
    public function annexes(): HasMany { return $this->hasMany(ContractAnnex::class)->orderBy('effective_on'); }
}
