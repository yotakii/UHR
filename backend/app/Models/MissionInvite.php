<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** دعوة عمل ضمن مهمة (FR-TM-026) — موضوع سير عمل مستقل عن المهمة */
class MissionInvite extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['from_on' => 'date', 'to_on' => 'date', 'decided_at' => 'datetime']; }
    protected function employeeId(): Attribute { return Attribute::get(fn () => Mission::whereKey($this->mission_id)->value('employee_id')); }
    public function mission(): BelongsTo { return $this->belongsTo(Mission::class); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Work invitation' : 'دعوة عمل'; }
}
