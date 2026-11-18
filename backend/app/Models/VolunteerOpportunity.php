<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerOpportunity extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date', 'fee' => 'float']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
}
