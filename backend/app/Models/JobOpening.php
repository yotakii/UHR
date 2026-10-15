<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOpening extends Model
{
    protected function casts(): array { return ['requirements' => 'array', 'posted_at' => 'date', 'salary_min' => 'float', 'salary_max' => 'float']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function candidates(): HasMany { return $this->hasMany(Candidate::class); }
    public function scopeOpen($q) { return $q->where('status', 'open'); }
}
