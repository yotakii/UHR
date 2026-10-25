<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceCycle extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date', 'weights' => 'array', 'opened_at' => 'datetime', 'approved_at' => 'datetime']; }
    public function reviews(): HasMany { return $this->hasMany(PerformanceReview::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
}
