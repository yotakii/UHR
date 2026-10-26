<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** إذن وصول لرواتب الآخرين (كل الشركة أو إدارة) — يعتمده المدير العام عبر المسار الموحد، صالح لساعات ثم ينتهي، ويُسحب في أي وقت */
class SalaryGrant extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['expires_at' => 'datetime', 'decided_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function revoker(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Salary data access' : 'إذن الاطلاع على الرواتب'; }

    public function isActive(): bool { return $this->status === 'approved' && $this->expires_at && $this->expires_at->isFuture(); }

    /** الحالة المعروضة: المعتمد بعد انتهاء مدته = expired */
    public function state(): string { return $this->status === 'approved' && ! $this->isActive() ? 'expired' : $this->status; }
}
