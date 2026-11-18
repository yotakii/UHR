<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class EmployeeCompetency extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'employee_competencies';
    protected function casts(): array { return []; }
}
