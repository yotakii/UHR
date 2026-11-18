<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdRating extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_ratings';
    protected function casts(): array { return ['scores' => 'array', 'avg' => 'float']; }
}
