<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdTrainer extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_trainers';
    protected function casts(): array { return ['specialties' => 'array', 'hourly_rate' => 'float', 'rating' => 'float', 'hours_given' => 'float']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function center(): BelongsTo { return $this->belongsTo(TdCenter::class, 'center_id'); }
}
