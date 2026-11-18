<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** اتفاقية / تكليف لغير موظف (متطوع، عقد تجريبي، استشاري) — موضوع سير عمل الاعتماد */
class Engagement extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'days' => 'array', 'relation_test' => 'array', 'pledges' => 'array', 'onboarding' => 'array', 'evaluation' => 'array',
            'signed_at' => 'datetime', 'decided_at' => 'datetime', 'ended_at' => 'datetime', 'end_warned_at' => 'datetime', 'amount' => 'float', 'expense_cap' => 'float'];
    }
    /** سير العمل يقرأ employee_id — غير الموظف ليس له ملف موظف */
    protected function employeeId(): Attribute { return Attribute::get(fn () => null); }
    public function external(): BelongsTo { return $this->belongsTo(External::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(Employee::class, 'supervisor_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function logs(): HasMany { return $this->hasMany(VolunteerLog::class); }
    public function workflowLabel(string $lang): string { return ($lang === 'en' ? 'Engagement ' : 'تكليف ').$this->ref; }
}
