<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حالة مخالفة: فتح ← إبلاغ ← تحقيق ← قرار (أو حفظ/انقضاء المهلة) */
class DisciplineCase extends Model
{
    use Concerns\BelongsToCompany;

    public const OPEN = ['open', 'investigated', 'pending_gm'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'discovered_on' => 'date:Y-m-d', 'deadline' => 'date:Y-m-d', 'suggested' => 'array', 'penalty' => 'array', 'investigation' => 'array',
            'deduct_days' => 'float', 'capped' => 'boolean', 'defended_at' => 'datetime', 'decided_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function type(): BelongsTo { return $this->belongsTo(ViolationType::class, 'violation_type_id'); }
    public function opener(): BelongsTo { return $this->belongsTo(User::class, 'opened_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
