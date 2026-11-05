<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** موافقة على عرض عمل يتجاوز ميزانية الشاغر (ملاحظتا الاختبار 56 و70) — موضوع في المسار الموحد */
class OfferApproval extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['total' => 'float', 'budget' => 'float', 'decided_at' => 'datetime']; }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function offer(): BelongsTo { return $this->belongsTo(Offer::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Job offer over budget' : 'عرض عمل يتجاوز الميزانية'; }
    /** المسار يقرأ employee_id من الموضوع — المتقدم ليس موظفاً بعد */
    public function getEmployeeIdAttribute(): ?int { return null; }
}
