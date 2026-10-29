<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قراءة السيرة الآلية لطلب توظيف — استرشادية فقط */
class CandidateReview extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;
    protected function casts(): array { return ['strengths' => 'array', 'gaps' => 'array', 'reviewed_at' => 'datetime']; }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
}
