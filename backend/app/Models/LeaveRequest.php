<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LeaveRequest extends Model
{
    use Concerns\BelongsToCompany;
    /** أنواع الطلب — LEAVE_TYPES في الديمو (أنواع الشركة الكاملة HR.01-01-F-03 في المهمة 07) */
    public const TYPES = ['annual' => ['إجازة سنوية', 'Annual leave'], 'sick' => ['إجازة مرضية', 'Sick leave'], 'unpaid' => ['إجازة بلا أجر', 'Unpaid leave'],
        'hourly' => ['إجازة ساعية', 'Hourly leave'], 'mission' => ['مهمة عمل', 'Work mission'], 'justify' => ['تبرير تأخير / غياب', 'Late / absence justification'],
        'emergency' => ['إجازة طارئة', 'Emergency leave'], 'injury' => ['إصابة عمل', 'Work injury'], 'marriage' => ['إجازة زواج', 'Marriage leave'], 'maternity' => ['إجازة أمومة', 'Maternity leave'],
        'paternity' => ['إجازة أبوة', 'Paternity leave'], 'hajj' => ['إجازة حج / عمرة', 'Hajj / Umrah leave'], 'death' => ['إجازة وفاة', 'Bereavement leave']];

    public const PENDING = ['pending_manager', 'pending_hr', 'pending_exec'];

    protected function casts(): array { return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d', 'ref_date' => 'date:Y-m-d', 'chain' => 'array', 'warnings' => 'array', 'days' => 'float', 'decided_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function substitute(): BelongsTo { return $this->belongsTo(Employee::class, 'substitute_id'); }
    public function doc(): BelongsTo { return $this->belongsTo(StoredFile::class, 'doc_file_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    /** اسم الطلب في الإشعارات والسجل: من أنواع الإجازات في السياسة */
    public function workflowLabel(string $lang): string
    {
        if ($this->type === 'justify') return $lang === 'en' ? 'Lateness/absence justification' : 'تبرير تأخير / غياب';
        $t = collect(\App\Support\Settings::policy('leave_types'))->firstWhere('key', $this->type);
        return $t ? $t[$lang] : (self::TYPES[$this->type][$lang === 'en' ? 1 : 0] ?? $this->type);
    }

    public function scopePending($q) { return $q->whereIn('status', self::PENDING); }
}
