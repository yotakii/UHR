<?php

namespace App\Models;

/** احتفال تلقائي في الساحة (12b): منشور واحد لكل نوع في اليوم (والترقية لكل موظف) — المفتاح يمنع التكرار */
class Celebration extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;
    protected function casts(): array { return ['employees' => 'array', 'on' => 'date']; }
}
