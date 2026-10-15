<?php

namespace App\Models;

/** سجل النماذج: الرمز والإصدار وتاريخه لتذييل الطباعة (SRS §10.4، §10.5) */
class FormTemplate extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['issued_on' => 'date', 'sensitive' => 'boolean']; }
}
