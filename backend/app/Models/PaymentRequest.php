<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب دفع للمالية خارج مسيّر الرواتب (FR-RS-009 / FR-VL-010) */
class PaymentRequest extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['amount' => 'float', 'decided_at' => 'datetime', 'paid_at' => 'datetime']; }
    public function external(): BelongsTo { return $this->belongsTo(External::class); }
}
