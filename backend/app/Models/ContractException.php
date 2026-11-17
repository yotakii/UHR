<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** طلب اعتماد قرار عقد مخالف للسياسة (استثناء) — المسار الموحد، سلسلة contract_exception */
class ContractException extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['decision' => 'array', 'decided_at' => 'datetime']; }
    public function case(): BelongsTo { return $this->belongsTo(ContractCase::class, 'contract_case_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Contract decision (exception)' : 'قرار عقد (استثناء)'; }
}
