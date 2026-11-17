<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** مكافأة مُنحت من نوع محكوم بالسياسة (BN-xx) — موضوع المسار الموحد (سلسلة bonus) */
class Bonus extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['basis' => 'array', 'amount' => 'float', 'decided_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function type(): BelongsTo { return $this->belongsTo(CodeItem::class, 'type_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return ($lang === 'en' ? 'Bonus ' : 'مكافأة ').$this->ref_no; }
}
