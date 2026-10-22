<?php

namespace App\Models;

/** بند في دليل المخالفات: الجزاء لأول/ثاني/ثالث/رابع مرة */
class ViolationType extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['penalties' => 'array', 'active' => 'boolean']; }
}
