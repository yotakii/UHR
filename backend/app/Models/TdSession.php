<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة 23 — موديول التأهيل والتدريب والتطوير */
class TdSession extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_sessions';
    protected function casts(): array { return ['meetings' => 'array', 'costs' => 'array', 'quotes' => 'array', 'logistics' => 'array', 'kpi' => 'array', 'roi' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'quotes_approved_at' => 'datetime', 'evaluated_at' => 'datetime', 'ai_summary' => 'array']; }
    public function program(): BelongsTo { return $this->belongsTo(TdProgram::class, 'program_id'); }
    public function trainer(): BelongsTo { return $this->belongsTo(TdTrainer::class, 'trainer_id'); }
    public function center(): BelongsTo { return $this->belongsTo(TdCenter::class, 'center_id'); }
    public function room(): BelongsTo { return $this->belongsTo(TdRoom::class, 'room_id'); }
    public function enrollments(): HasMany { return $this->hasMany(TdEnrollment::class, 'session_id'); }
}
