<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdSurvey extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_surveys';
    protected function casts(): array { return ['ratings' => 'array', 'answers' => 'array', 'avg' => 'float']; }
}
