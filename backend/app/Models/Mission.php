<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** مهمة عمل (عامة أو سفر) — موضوع المسار الموحد (سلسلة mission) للأنواع ذات الأثر المالي */
class Mission extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'due_on' => 'date', 'close_due' => 'date', 'stages' => 'array', 'travel' => 'array', 'estimate' => 'array', 'advance' => 'array', 'settlement' => 'array',
            'log' => 'array', 'attachments' => 'array', 'decided_at' => 'datetime', 'done_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function type(): BelongsTo { return $this->belongsTo(CodeItem::class, 'type_id'); }
    public function category(): BelongsTo { return $this->belongsTo(CodeItem::class, 'category_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function owner(): BelongsTo { return $this->belongsTo(Employee::class, 'owner_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(Mission::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Mission::class, 'parent_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'requester_department_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(CodeItem::class, 'cost_center_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function comments(): HasMany { return $this->hasMany(MissionComment::class)->orderBy('id'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return ($lang === 'en' ? 'Mission ' : 'مهمة ').$this->ref_no; }
}
