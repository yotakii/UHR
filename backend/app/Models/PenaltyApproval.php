<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** طلب موافقة المدير العام على جزاء أشد (المسار الموحد، سلسلة penalty) */
class PenaltyApproval extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['penalty' => 'array', 'decided_at' => 'datetime']; }
    public function case(): BelongsTo { return $this->belongsTo(DisciplineCase::class, 'discipline_case_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Disciplinary penalty' : 'جزاء مخالفة'; }
}
