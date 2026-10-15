<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected function casts(): array { return ['issued_at' => 'datetime']; }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function approvals(): HasMany { return $this->hasMany(AppointmentApproval::class)->orderBy('level'); }
    public function isComplete(): bool { return $this->approvals->every(fn ($a) => $a->status === 'approved'); }
}
