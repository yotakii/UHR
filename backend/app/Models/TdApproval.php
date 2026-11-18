<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** موضوع سير عمل في التدريب: تعديل خطة معتمدة (BR-18) أو اختيار عرض تدريب خارجي (BR-05) */
class TdApproval extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_approvals';
    protected function casts(): array { return ['data' => 'array', 'cost_delta' => 'float', 'decided_at' => 'datetime']; }
    protected function employeeId(): Attribute { return Attribute::get(fn () => null); }
    public function plan(): BelongsTo { return $this->belongsTo(TdPlan::class, 'plan_id'); }
    public function item(): BelongsTo { return $this->belongsTo(TdPlanItem::class, 'item_id'); }
    public function session(): BelongsTo { return $this->belongsTo(TdSession::class, 'session_id'); }
    public function workflowLabel(string $lang): string
    {
        return $this->kind === 'quote' ? ($lang === 'en' ? 'Training quote choice' : 'اختيار عرض تدريب') : ($lang === 'en' ? 'Training plan change' : 'تعديل خطة التدريب');
    }
}
