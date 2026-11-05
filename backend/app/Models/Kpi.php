<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مؤشر أداء (KPI) لموظف أو إدارة أو للشركة: هدف واتجاه وفترة قياس، وقيمه الفعلية لكل فترة (ملاحظة 62) */
class Kpi extends Model
{
    use Concerns\BelongsToCompany;

    public const UNITS = ['number', 'percent', 'currency', 'count', 'hours', 'days'];
    public const PERIODS = ['month', 'quarter', 'year'];

    protected function casts(): array { return ['target' => 'float', 'active' => 'boolean']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function entries(): HasMany { return $this->hasMany(KpiEntry::class)->orderByDesc('period'); }
}
