<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scan extends Model
{
    protected bool $audit = false;
    public $timestamps = false;
    protected function casts(): array { return ['ts' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
}
