<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interview extends Model
{
    protected function casts(): array { return ['ratings' => 'array', 'date' => 'date']; }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
}
