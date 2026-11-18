<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdEnrollment extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_enrollments';
    protected function casts(): array { return ['attendance' => 'array', 'failure' => 'array', 'apply_plan' => 'array', 'manager_ok' => 'boolean', 'manager_at' => 'datetime', 'attendance_pct' => 'float', 'pre_score' => 'float', 'post_score' => 'float', 'cost' => 'float']; }
    public function session(): BelongsTo { return $this->belongsTo(TdSession::class, 'session_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
