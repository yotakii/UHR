<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdQualification extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_qualifications';
    protected function casts(): array { return ['stations' => 'array', 'evaluation' => 'array']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
