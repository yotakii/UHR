<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected function casts(): array { return ['ratings' => 'array', 'score' => 'float']; }
    public function cycle(): BelongsTo { return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
