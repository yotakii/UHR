<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تعليق على مهمة — الوصول عبر المهمة (المعزولة بالشركة) */
class WorkTaskComment extends Model
{
    protected bool $audit = false;
    public function task(): BelongsTo { return $this->belongsTo(WorkTask::class, 'work_task_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
