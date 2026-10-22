<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إشعار الجهة الخارجية بانتهاء الخدمة (D-17): prepared ← sent، أو blocked بلا موافقة موثقة */
class ExitNotice extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['addressee' => 'array', 'consent' => 'boolean', 'sent_at' => 'datetime']; }
    public function resignation(): BelongsTo { return $this->belongsTo(Resignation::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
