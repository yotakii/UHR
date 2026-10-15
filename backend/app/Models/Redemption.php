<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    use Concerns\BelongsToCompany;
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reward(): BelongsTo { return $this->belongsTo(Reward::class); }
}
