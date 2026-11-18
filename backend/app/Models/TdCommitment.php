<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdCommitment extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_commitments';
    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date', 'signed_at' => 'datetime', 'decided_at' => 'datetime', 'cost' => 'float', 'recovery' => 'float']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
