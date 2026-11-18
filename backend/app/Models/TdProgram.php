<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdProgram extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_programs';
    protected function casts(): array { return ['modules' => 'array', 'prerequisites' => 'array', 'competencies' => 'array', 'materials' => 'array', 'impact_items' => 'array', 'mandatory' => 'boolean', 'has_test' => 'boolean', 'active' => 'boolean', 'reviewed_on' => 'date', 'hours' => 'float']; }
}
