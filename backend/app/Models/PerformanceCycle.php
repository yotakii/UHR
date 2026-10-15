<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceCycle extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date', 'weights' => 'array']; }
    public function reviews(): HasMany { return $this->hasMany(PerformanceReview::class); }
}
