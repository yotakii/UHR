<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حركة واردة من بصّامة (رقم الموظف على الجهاز + الوقت) */
class DevicePunch extends Model
{
    use Concerns\BelongsToCompany;
    protected bool $audit = false;
    public const UPDATED_AT = null;
    protected function casts(): array { return ['punched_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
}
