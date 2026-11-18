<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deliverable extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['due_on' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'payment' => 'float']; }
    public function assignment(): BelongsTo { return $this->belongsTo(Assignment::class); }
}
