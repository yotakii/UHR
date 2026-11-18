<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdImpact extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_impacts';
    protected function casts(): array { return ['ratings' => 'array', 'due_on' => 'date', 'reminded_at' => 'datetime', 'escalated_at' => 'datetime', 'done_at' => 'datetime', 'avg' => 'float']; }
    public function enrollment(): BelongsTo { return $this->belongsTo(TdEnrollment::class, 'enrollment_id'); }
}
