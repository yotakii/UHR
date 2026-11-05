<?php

namespace App\Models;

/** جدول دوام (ثابت أو ورديات) — ملاحظتا الاختبار 24 و76 */
class WorkSchedule extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['shifts' => 'array', 'work_days' => 'array', 'season_from' => 'date:Y-m-d', 'season_to' => 'date:Y-m-d', 'active' => 'boolean']; }
}
