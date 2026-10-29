<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** اختبار متقدم واحد (محاولة): رابط بمدة ← إجابات ← تصحيح ← مراجعة HR. الرمز سرّي ولا يظهر في السجل */
class CandidateTest extends Model
{
    use Concerns\BelongsToCompany;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'answers' => 'array', 'scores' => 'array', 'sent_at' => 'datetime', 'expires_at' => 'datetime',
            'started_at' => 'datetime', 'deadline_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function jobTest(): BelongsTo { return $this->belongsTo(JobTest::class); }

    /** انتهت المهلة: رابط لم يُفتح قبل انتهاء صلاحيته، أو اختبار بدأ وتجاوز وقته */
    public function overdue(): bool
    {
        return ($this->status === 'sent' && $this->expires_at->isPast()) || ($this->status === 'started' && $this->deadline_at?->copy()->addMinute()->isPast());
    }
}
