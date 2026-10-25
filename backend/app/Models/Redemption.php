<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['decided_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reward(): BelongsTo { return $this->belongsTo(Reward::class); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by_user_id'); }
}
