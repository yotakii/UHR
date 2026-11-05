<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** منشور في «الساحة» */
class SpacePost extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['images' => 'array', 'poll' => 'array', 'mentions' => 'array', 'meta' => 'array', 'pinned_at' => 'datetime', 'edited_at' => 'datetime', 'hidden_at' => 'datetime']; }
    public function community(): BelongsTo { return $this->belongsTo(Community::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function comments(): HasMany { return $this->hasMany(SpaceComment::class, 'post_id'); }
    public function votes(): HasMany { return $this->hasMany(SpaceVote::class, 'post_id'); }
}
