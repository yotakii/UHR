<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** عامل من غير الموظفين (الجزء E): متطوع، عقد تجريبي، استشاري، خبير، متدرب — سجل منفصل لا يدخل التعداد ولا الرواتب */
class External extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array
    {
        return ['dob' => 'date', 'emergency' => 'array', 'areas' => 'array', 'availability' => 'array', 'specialties' => 'array', 'sectors' => 'array', 'keywords' => 'array',
            'languages' => 'array', 'work_modes' => 'array', 'documents' => 'array', 'available_from' => 'date', 'last_engaged_at' => 'datetime', 'review_due' => 'date',
            'review_requested_at' => 'datetime', 'rate' => 'float', 'avg_rating' => 'float'];
    }
    public function category(): BelongsTo { return $this->belongsTo(CodeItem::class, 'worker_category_id'); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(Employee::class, 'supervisor_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function engagements(): HasMany { return $this->hasMany(Engagement::class); }
}
