<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use Concerns\BelongsToCompany;
    /** أنواع الطلب — LEAVE_TYPES في الديمو (أنواع الشركة الكاملة HR.01-01-F-03 في المهمة 07) */
    public const TYPES = ['annual' => ['إجازة سنوية', 'Annual leave'], 'sick' => ['إجازة مرضية', 'Sick leave'], 'unpaid' => ['إجازة بلا أجر', 'Unpaid leave'],
        'hourly' => ['مغادرة ساعية', 'Hourly permission'], 'mission' => ['مهمة رسمية', 'Official mission'], 'justify' => ['تبرير تأخير / غياب', 'Late / absence justification']];

    protected function casts(): array { return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d', 'ref_date' => 'date:Y-m-d', 'chain' => 'array']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function scopePending($q) { return $q->whereIn('status', ['pending_manager', 'pending_hr']); }
}
