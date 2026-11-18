<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerLog extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['date' => 'date', 'hours' => 'float', 'approved_at' => 'datetime']; }
    public function engagement(): BelongsTo { return $this->belongsTo(Engagement::class); }
}
