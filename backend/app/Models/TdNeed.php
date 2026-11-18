<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdNeed extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_needs';
    protected function casts(): array { return ['employees' => 'array', 'factors' => 'array', 'evidence' => 'array', 'objective' => 'array', 'score' => 'float', 'estimated_cost' => 'float']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function competency(): BelongsTo { return $this->belongsTo(Competency::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function program(): BelongsTo { return $this->belongsTo(TdProgram::class, 'program_id'); }
}
