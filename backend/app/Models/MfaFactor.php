<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaFactor extends Model
{
    protected $hidden = ['totp_secret', 'backup_hashes'];
    protected function casts(): array { return ['totp_secret' => 'encrypted', 'backup_hashes' => 'array', 'enrolled_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
