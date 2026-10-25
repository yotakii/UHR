<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** خطة التعاقب لمنصب حرج (D-23) */
class SuccessionPlan extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['competencies' => 'array', 'next_review' => 'date', 'reviewed_at' => 'datetime', 'gap_alerted_at' => 'datetime']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function incumbent(): BelongsTo { return $this->belongsTo(Employee::class, 'incumbent_id'); }
    public function candidates(): HasMany { return $this->hasMany(SuccessionCandidate::class); }
}
