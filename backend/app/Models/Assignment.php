<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** تكليف خبير من القائمة بشروط مرجعية (WF-RS-02) */
class Assignment extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array
    {
        return ['tor' => 'array', 'shortlist' => 'array', 'relation_test' => 'array', 'pledges' => 'array', 'starts_on' => 'date', 'ends_on' => 'date',
            'contracted_at' => 'datetime', 'closed_at' => 'datetime', 'budget' => 'float', 'total_fee' => 'float'];
    }
    public function expert(): BelongsTo { return $this->belongsTo(External::class, 'external_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function deliverables(): HasMany { return $this->hasMany(Deliverable::class)->orderBy('due_on')->orderBy('id'); }
    public function rating(): HasOne { return $this->hasOne(ExpertRating::class); }
}
