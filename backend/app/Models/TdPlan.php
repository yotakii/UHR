<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الخطة السنوية للتدريب والتطوير F-04 — موضوع سير عمل الاعتماد (BR-10) */
class TdPlan extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_plans';
    protected function casts(): array { return ['budget_split' => 'array', 'baseline' => 'array', 'decided_at' => 'datetime', 'budget' => 'float']; }
    protected function employeeId(): Attribute { return Attribute::get(fn () => null); }
    public function items(): HasMany { return $this->hasMany(TdPlanItem::class, 'plan_id'); }
    public function workflowLabel(string $lang): string { return ($lang === 'en' ? 'Training plan ' : 'خطة التدريب ').$this->year; }
}
