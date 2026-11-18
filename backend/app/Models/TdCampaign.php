<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdCampaign extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_campaigns';
    protected function casts(): array { return ['scope_ids' => 'array', 'responses' => 'array', 'opens_on' => 'date', 'closes_on' => 'date', 'reminded_at' => 'datetime']; }
}
