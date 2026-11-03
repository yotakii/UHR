<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceReport extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['decided_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
