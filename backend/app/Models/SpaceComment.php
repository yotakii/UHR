<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceComment extends Model
{
    protected function casts(): array { return ['mentions' => 'array', 'edited_at' => 'datetime', 'hidden_at' => 'datetime']; }
    public function post(): BelongsTo { return $this->belongsTo(SpacePost::class, 'post_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
