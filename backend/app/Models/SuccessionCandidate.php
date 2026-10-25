<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuccessionCandidate extends Model
{
    use Concerns\BelongsToCompany;
    public function plan(): BelongsTo { return $this->belongsTo(SuccessionPlan::class, 'succession_plan_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function developmentPlan(): BelongsTo { return $this->belongsTo(DevelopmentPlan::class); }
    public function nominator(): BelongsTo { return $this->belongsTo(User::class, 'nominated_by'); }
}
