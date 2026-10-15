<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use Concerns\BelongsToCompany;
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function jobOpening(): BelongsTo { return $this->belongsTo(JobOpening::class); }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
}
