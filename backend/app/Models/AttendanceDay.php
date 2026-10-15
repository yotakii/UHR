<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDay extends Model
{
    use Concerns\BelongsToCompany;
    protected bool $audit = false;
    protected function casts(): array { return ['date' => 'date:Y-m-d', 'in_at' => 'datetime', 'out_at' => 'datetime', 'justified' => 'boolean']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
