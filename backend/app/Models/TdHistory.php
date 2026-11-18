<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تدريب سابق لاستخدام النظام (FR-TD-104) — يظهر في السجل F-06 */
class TdHistory extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_history';
    protected function casts(): array { return ['from_on' => 'date', 'to_on' => 'date', 'hours' => 'float', 'cost' => 'float']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
