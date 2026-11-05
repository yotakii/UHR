<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** القيمة الفعلية لمؤشر في فترة — الوصول عبر المؤشر (المعزول بالشركة) */
class KpiEntry extends Model
{
    protected function casts(): array { return ['actual' => 'float', 'achievement' => 'float']; }
    public function kpi(): BelongsTo { return $this->belongsTo(Kpi::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }
}
