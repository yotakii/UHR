<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب تغيير في دليل الترميز (WF-CS-02) أو إعادة هيكلة (WF-CS-03: دمج/نقل تبعية) */
class CodeChangeRequest extends Model
{
    use BelongsToCompany;

    protected function casts(): array { return ['payload' => 'array', 'impact' => 'array', 'effective_on' => 'date', 'decided_at' => 'datetime', 'applied_at' => 'datetime']; }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
