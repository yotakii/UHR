<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequest extends Model
{
    use Concerns\BelongsToCompany;
    public const KINDS = ['salary_cert' => ['شهادة راتب', 'Salary certificate'], 'experience_cert' => ['شهادة خبرة / تعريف', 'Employment letter'],
        'advance' => ['سلفة على الراتب', 'Salary advance'], 'data_update' => ['تحديث بيانات شخصية', 'Personal data update']];

    protected function casts(): array { return ['amount' => 'float', 'done_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
