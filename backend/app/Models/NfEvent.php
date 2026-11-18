<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حدث خام من جهاز (FR-NF-009): يُحفظ كما وصل مع القرار وسببه، ولا يُعدَّل إلا حقل المراجعة */
class NfEvent extends Model
{
    use Concerns\BelongsToCompany;
    public $timestamps = false;
    protected bool $audit = false;
    protected function casts(): array { return ['occurred_at' => 'datetime', 'received_at' => 'datetime', 'reviewed_at' => 'datetime']; }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function mission(): BelongsTo { return $this->belongsTo(Mission::class); }
}
