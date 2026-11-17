<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ملحق عقد: تعديل المنصب أو الدرجة دون عقد جديد */
class ContractAnnex extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['effective_on' => 'date', 'changes' => 'array', 'applied_at' => 'datetime']; }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
