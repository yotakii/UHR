<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resignation extends Model
{
    protected function casts(): array { return ['last_day' => 'date:Y-m-d', 'exit' => 'array', 'clearance' => 'array', 'chain' => 'array', 'settlement_total' => 'float', 'closed_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function isOpen(): bool { return ! in_array($this->status, ['closed', 'rejected'], true); }
}
