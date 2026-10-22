<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** شكوى موظف HR.01-01-F-02 — سرية: للموارد البشرية فقط (لا يراها المدير) */
class Complaint extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['responded_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function responder(): BelongsTo { return $this->belongsTo(User::class, 'responded_by'); }
}
