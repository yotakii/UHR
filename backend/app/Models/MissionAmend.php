<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تمديد/تقصير مهمة معتمدة بزيادة كلفة — يُعتمد الفرق قبل التطبيق (FR-TM-024) */
class MissionAmend extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['depart_on' => 'date', 'return_on' => 'date', 'before' => 'array', 'after' => 'array', 'decided_at' => 'datetime']; }
    protected function employeeId(): Attribute { return Attribute::get(fn () => Mission::whereKey($this->mission_id)->value('employee_id')); }
    public function mission(): BelongsTo { return $this->belongsTo(Mission::class); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Mission amendment' : 'تعديل مهمة'; }
}
