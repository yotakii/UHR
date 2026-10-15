<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected function casts(): array { return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d', 'ref_date' => 'date:Y-m-d', 'chain' => 'array']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function scopePending($q) { return $q->whereIn('status', ['pending_manager', 'pending_hr']); }
}
