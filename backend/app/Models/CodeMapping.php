<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

/** جدول الربط بين الرمز القديم والجديد بعد الدمج أو نقل التبعية — التقارير التاريخية تعرض القديم كما كان */
class CodeMapping extends Model
{
    use BelongsToCompany;

    protected function casts(): array { return ['effective_on' => 'date', 'moved' => 'array']; }
}
