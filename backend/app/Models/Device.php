<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use Concerns\BelongsToCompany;
    protected $hidden = ['key_hash'];
    protected function casts(): array { return ['last_seen_at' => 'datetime', 'active' => 'boolean']; }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
}
