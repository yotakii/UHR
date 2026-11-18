<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdCbp extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_cbps';
    protected function casts(): array { return ['goals' => 'array', 'starts_on' => 'date', 'ends_on' => 'date']; }
}
