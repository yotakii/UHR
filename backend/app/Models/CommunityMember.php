<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMember extends Model
{
    protected bool $audit = false;
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function community(): BelongsTo { return $this->belongsTo(Community::class); }
}
