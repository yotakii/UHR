<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * طلب خروج (M11، HR.01-03): استقالة F-02 أو إنهاء عقد F-03. المسار الموحد (المدير ← HR ← المدير العام) ثم
 * clearance (تسليم المهام F-05 + تسليم العهدة F-07 + المخالصة) ← closed. rejected / cancelled (رجوع عن الاستقالة).
 */
class Resignation extends Model
{
    use Concerns\BelongsToCompany;

    public const PENDING = ['pending_manager', 'pending_hr', 'pending_exec'];
    public const OPEN = ['pending_manager', 'pending_hr', 'pending_exec', 'clearance'];

    protected function casts(): array
    {
        return ['last_day' => 'date:Y-m-d', 'notice_until' => 'date:Y-m-d', 'exit' => 'array', 'clearance' => 'array', 'chain' => 'array', 'settlement_total' => 'float', 'closed_at' => 'datetime',
            'reasons' => 'array', 'survey' => 'array', 'hr_interview' => 'array', 'termination' => 'array', 'handover' => 'array', 'withdrawal' => 'array', 'settlement' => 'array',
            'followups' => 'array', 'notice_waived' => 'boolean', 'rehire' => 'boolean', 'clearance_issued_at' => 'datetime', 'service_cert_at' => 'datetime', 'account_moved_at' => 'datetime',
            'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function successor(): BelongsTo { return $this->belongsTo(User::class, 'successor_user_id'); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function notices(): HasMany { return $this->hasMany(ExitNotice::class); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function isOpen(): bool { return in_array($this->status, self::OPEN, true); }
    public function workflowLabel(string $lang): string
    {
        return $this->kind === 'termination' ? ($lang === 'en' ? 'Contract termination' : 'إنهاء عقد') : ($lang === 'en' ? 'Resignation' : 'طلب استقالة');
    }
}
