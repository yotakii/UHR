<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** خطة التطوير (D-24): فردية، أو لإدارة، أو للشركة كلها (ملاحظة 69) */
class DevelopmentPlan extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['goals' => 'array', 'reviews' => 'array', 'next_review' => 'date', 'activated_at' => 'datetime', 'completed_at' => 'datetime', 'review_alerted_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function activities(): HasMany { return $this->hasMany(DevelopmentActivity::class)->orderBy('due_on')->orderBy('id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
