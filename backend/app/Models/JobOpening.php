<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الشاغر. مكان النشر (SRS §10.15): internal للموظفين فقط، company على صفحة الشركة، public على صفحة الشركة + البوابة العامة
 * (بعد موافقة المنصة إذا كانت المراجعة مفعّلة للشركة).
 */
class JobOpening extends Model
{
    use Concerns\BelongsToCompany;

    public const VISIBILITY = ['internal', 'company', 'public'];
    public const TYPES = [
        'full_time' => ['ar' => 'دوام كامل', 'en' => 'Full-time'], 'part_time' => ['ar' => 'دوام جزئي', 'en' => 'Part-time'],
        'shifts' => ['ar' => 'ورديات', 'en' => 'Shifts'], 'contract' => ['ar' => 'عقد مؤقت', 'en' => 'Contract'], 'remote' => ['ar' => 'عن بُعد', 'en' => 'Remote'],
    ];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'posted_at' => 'date', 'closes_at' => 'date', 'portal_reviewed_at' => 'datetime',
            'salary_min' => 'float', 'salary_max' => 'float', 'hard_to_fill' => 'boolean', 'requires_photo' => 'boolean'];
    }

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function candidates(): HasMany { return $this->hasMany(Candidate::class); }

    public function scopeOpen($q) { return $q->where('status', 'open'); }

    /** مفتوح ولم ينتهِ تاريخ إغلاقه */
    public function scopeAccepting(Builder $q): Builder
    {
        return $q->where('status', 'open')->where(fn ($w) => $w->whereNull('closes_at')->orWhereDate('closes_at', '>=', now()->toDateString()));
    }

    /** يظهر على صفحة الشركة (صفحتها العامة + التقديم عبر الرابط) */
    public function scopeOnCompanyPage(Builder $q): Builder
    {
        return $q->accepting()->whereIn('visibility', ['company', 'public'])->whereHas('company', fn ($c) => $c->where('status', 'active'));
    }

    /** يظهر في فرص البوابة العامة */
    public function scopeOnPortal(Builder $q): Builder
    {
        return $q->accepting()->where('visibility', 'public')->where('portal_status', 'approved')
            ->whereHas('company', fn ($c) => $c->where('status', 'active')->where('portal_enabled', true));
    }

    public function acceptingApplications(): bool
    {
        return $this->status === 'open' && (! $this->closes_at || $this->closes_at->gte(now()->startOfDay())) && $this->visibility !== 'internal';
    }
}
