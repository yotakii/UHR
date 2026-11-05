<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إسناد جدول/وردية لموظف لفترة (مناوبة أو عقد موسمي) */
class ShiftAssignment extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function schedule(): BelongsTo { return $this->belongsTo(WorkSchedule::class, 'work_schedule_id'); }
}
