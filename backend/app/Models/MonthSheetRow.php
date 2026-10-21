<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** صف موظف في الكشف الشهري — يُعاد حسابه بالكامل عند كل احتساب (التدقيق على مستوى الكشف) */
class MonthSheetRow extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;

    protected function casts(): array { return ['data' => 'array', 'money' => 'array', 'salary' => 'float']; }
    public function sheet(): BelongsTo { return $this->belongsTo(MonthSheet::class, 'month_sheet_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
