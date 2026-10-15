<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Notice extends Model
{
    protected bool $audit = false;
    public $timestamps = false;
    protected function casts(): array { return ['at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reads(): BelongsToMany { return $this->belongsToMany(User::class, 'notice_reads')->withPivot('read_at'); }
}
