<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** اعتماد حضور (FR-NF-005): بطاقة NFC أو رقم بصمة أو وجه أو هاتف — يُبحث عنه داخل شركة الجهاز فقط */
class Credential extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['valid_from' => 'date', 'valid_to' => 'date', 'revoked_at' => 'datetime', 'memory_token' => 'encrypted']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
