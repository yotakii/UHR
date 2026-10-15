<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected bool $audit = false;
    public $timestamps = false;
    protected function casts(): array { return ['before' => 'array', 'after' => 'array', 'at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function subject(): MorphTo { return $this->morphTo(); }
}
