<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نموذج تقييم 360: ذاتي أو المدير أو زميل — درجة لكل كفاءة على سلّم الشركة */
class PerformanceForm extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['ratings' => 'array', 'submitted_at' => 'datetime']; }
    public function review(): BelongsTo { return $this->belongsTo(PerformanceReview::class, 'performance_review_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function rater(): BelongsTo { return $this->belongsTo(Employee::class, 'rater_employee_id'); }
}
