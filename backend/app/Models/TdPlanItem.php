<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdPlanItem extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_plan_items';
    protected function casts(): array { return ['changes' => 'array', 'estimated_cost' => 'float', 'actual_cost' => 'float']; }
    public function plan(): BelongsTo { return $this->belongsTo(TdPlan::class, 'plan_id'); }
    public function program(): BelongsTo { return $this->belongsTo(TdProgram::class, 'program_id'); }
}
