<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل النقاط (SRS §10.9): كل إضافة أو خصم بسببه — هو نفسه سجل تدقيق النقاط */
class PointLog extends Model
{
    use Concerns\BelongsToCompany;
    protected bool $audit = false;
    protected function casts(): array { return ['at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function referral(): BelongsTo { return $this->belongsTo(Referral::class); }
}
