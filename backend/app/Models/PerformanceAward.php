<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** استحقاق المكافأة حسب نتيجة التقييم (D-28): review ← calculated ← approved ← paid */
class PerformanceAward extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['band' => 'array', 'score' => 'float', 'amount' => 'float', 'decided_at' => 'datetime', 'paid_at' => 'datetime']; }
    public function review(): BelongsTo { return $this->belongsTo(PerformanceReview::class, 'performance_review_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
