<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** التكليف بالعمل الإضافي HR.01-01-F-04: المدير يكلّف موظفاً بساعات إضافية ليوم محدد، والموارد البشرية تعتمد */
class OvertimeRequest extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['date' => 'date:Y-m-d', 'decided_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Overtime assignment (F-04)' : 'تكليف بعمل إضافي (F-04)'; }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
}
