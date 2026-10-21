<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** خطوة اعتماد في المسار الموحد (SRS §10.1) — لكل طلب (إجازة، تبرير، تكليف إضافي) خطواته بالترتيب */
class RequestStep extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['activated_at' => 'datetime', 'decided_at' => 'datetime', 'reminded_at' => 'datetime']; }
    public function subject(): MorphTo { return $this->morphTo(); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'acted_by'); }
    public function delegator(): BelongsTo { return $this->belongsTo(User::class, 'delegate_for'); }
}
