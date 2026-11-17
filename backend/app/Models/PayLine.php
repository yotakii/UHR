<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند راتب برمز عنصر من الدليل ومرجع مصدره — الجسر الوحيد من المهمات والمكافآت إلى الكشف الشهري */
class PayLine extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['amount' => 'float', 'amount_pay' => 'float', 'fx_rate' => 'float']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function element(): BelongsTo { return $this->belongsTo(CodeItem::class, 'element_id'); }
}
