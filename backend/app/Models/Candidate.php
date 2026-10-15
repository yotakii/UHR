<?php

namespace App\Models;

use App\Models\Concerns\HasEventLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidate extends Model
{
    use Concerns\BelongsToCompany;
    use HasEventLog;

    public const STAGES = ['new', 'screening', 'interview', 'technical', 'offer', 'hired', 'rejected'];

    protected function casts(): array
    {
        return [
            'stage_at' => 'date:Y-m-d', 'dob' => 'date:Y-m-d', 'availability' => 'date:Y-m-d',
            'education' => 'array', 'experience' => 'array', 'refs' => 'array',
            'shifts' => 'boolean', 'travel' => 'boolean', 'public_sector' => 'boolean', 'conviction' => 'boolean',
            'salary' => 'float', 'consent_at' => 'datetime', 'keep_cv' => 'boolean',
        ];
    }

    public function jobOpening(): BelongsTo { return $this->belongsTo(JobOpening::class); }
    public function cv(): BelongsTo { return $this->belongsTo(StoredFile::class, 'cv_file_id'); }
    public function referrer(): BelongsTo { return $this->belongsTo(Employee::class, 'referrer_id'); }
    public function internalEmployee(): BelongsTo { return $this->belongsTo(Employee::class, 'internal_employee_id'); }
    public function interview(): HasOne { return $this->hasOne(Interview::class); }
    public function offer(): HasOne { return $this->hasOne(Offer::class); }
    public function appointment(): HasOne { return $this->hasOne(Appointment::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function photo(): BelongsTo { return $this->belongsTo(StoredFile::class, 'photo_file_id'); }
}
