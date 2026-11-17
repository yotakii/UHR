<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حالة تجديد عقد أو تقييم فترة تجربة (WF-CR-01 / WF-CR-02) */
class ContractCase extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array
    {
        return ['due_on' => 'date', 'manager_due' => 'date', 'hr_due' => 'date', 'summary' => 'array', 'evaluation' => 'array', 'signatures' => 'array', 'review' => 'array',
            'suggestion' => 'array', 'decision' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime', 'escalated_at' => 'datetime', 'lapsed_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function newContract(): BelongsTo { return $this->belongsTo(Contract::class, 'new_contract_id'); }
    public function evaluator(): BelongsTo { return $this->belongsTo(Employee::class, 'evaluator_id'); }
}
