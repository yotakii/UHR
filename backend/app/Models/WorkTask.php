<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مهمة عمل (ملاحظتا 61/62): مكلَّف، أولوية، موعد، تقدّم، واعتماد الإنجاز من المكلِّف — ونقاط عند الإنجاز */
class WorkTask extends Model
{
    use Concerns\BelongsToCompany;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['todo', 'in_progress', 'review', 'done', 'cancelled'];

    protected function casts(): array { return ['due_on' => 'date', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'completed_at' => 'datetime', 'alerted_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function kpi(): BelongsTo { return $this->belongsTo(Kpi::class); }
    public function comments(): HasMany { return $this->hasMany(WorkTaskComment::class)->orderBy('id'); }
}
