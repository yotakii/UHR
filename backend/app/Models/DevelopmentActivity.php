<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevelopmentActivity extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['due_on' => 'date', 'done_at' => 'datetime', 'alerted_at' => 'datetime']; }
    public function plan(): BelongsTo { return $this->belongsTo(DevelopmentPlan::class, 'development_plan_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(Employee::class, 'owner_employee_id'); }
}
