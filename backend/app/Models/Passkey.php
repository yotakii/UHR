<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Passkey extends Model
{
    protected $hidden = ['public_key'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
