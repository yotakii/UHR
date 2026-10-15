<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentApproval extends Model
{
    public $timestamps = false;
    protected function casts(): array { return ['approved_at' => 'datetime']; }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
}
