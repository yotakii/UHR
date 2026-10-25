<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PerformanceReview extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['ratings' => 'array', 'score' => 'float', 'calibrated' => 'boolean', 'calibrated_at' => 'datetime', 'approved_at' => 'datetime']; }
    public function cycle(): BelongsTo { return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function forms(): HasMany { return $this->hasMany(PerformanceForm::class); }
    public function award(): HasOne { return $this->hasOne(PerformanceAward::class); }
}
