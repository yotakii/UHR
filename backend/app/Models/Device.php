<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use Concerns\BelongsToCompany;
    protected $hidden = ['key_hash', 'secret', 'activation_hash'];
    protected function casts(): array { return ['last_seen_at' => 'datetime', 'active' => 'boolean', 'zk' => 'array', 'last_sync_at' => 'datetime', 'last_sync' => 'array', 'secret' => 'encrypted', 'require_signature' => 'boolean', 'activation_expires_at' => 'datetime', 'offline_alerted_at' => 'datetime', 'gateway' => 'array']; }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
}
