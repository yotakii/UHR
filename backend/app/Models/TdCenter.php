<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdCenter extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_centers';
    protected function casts(): array { return ['quality' => 'float']; }
}
