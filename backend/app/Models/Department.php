<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected function casts(): array { return ['active' => 'boolean']; }
    public function head(): BelongsTo { return $this->belongsTo(Employee::class, 'head_id'); }
    public function employees(): HasMany { return $this->hasMany(Employee::class); }
}
