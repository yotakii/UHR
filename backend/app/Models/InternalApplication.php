<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** طلب داخلي F-27 (D-22): بالمسار الموحد للمدير المباشر فقط؛ القبول يحوّله متقدماً داخلياً في مسار التوظيف */
class InternalApplication extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['decided_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function jobOpening(): BelongsTo { return $this->belongsTo(JobOpening::class); }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { return $lang === 'en' ? 'Internal application' : 'طلب داخلي'; }
}
