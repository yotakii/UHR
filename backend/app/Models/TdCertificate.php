<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdCertificate extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_certificates';
    protected function casts(): array { return ['issued_on' => 'date', 'valid_to' => 'date', 'alerts' => 'array']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
