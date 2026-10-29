<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** أسئلة الاختبار الأولي لشاغر (D-34): مسودة يعدّلها HR ← معتمد قبل الإرسال */
class JobTest extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['questions' => 'array', 'approved_at' => 'datetime']; }
    public function jobOpening(): BelongsTo { return $this->belongsTo(JobOpening::class); }
}
