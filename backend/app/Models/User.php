<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\Concerns\Auditable;
use Illuminate\Notifications\Notifiable;

/**
 * حساب دخول: مرتبط بموظف أو بمتقدم، أو حساب إدارة فقط.
 * الواجهة تُختار حسب الأدوار (SRS §4.1).
 */
class User extends Authenticatable
{
    use Auditable, Notifiable;

    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token'];

    /** أدوار لوحة الإدارة (مسؤول التوظيف يدخل نفس الإطار بمجموعة التوظيف فقط — SRS §10.2) */
    public const ADMIN_ROLES = ['admin', 'hr_director', 'hr_officer', 'finance', 'executive', 'ceo', 'recruiter'];
    /** من يدير الشواغر وينشرها */
    public const JOB_ROLES = ['admin', 'hr_director', 'hr_officer', 'recruiter'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    /** طلبات المتقدم في كل الشركات (حساب واحد على المنصة — SRS §10.15) */
    public function candidates(): HasMany { return $this->hasMany(Candidate::class)->withoutGlobalScope('company'); }
    public function mfa(): HasOne { return $this->hasOne(MfaFactor::class); }
    public function passkeys(): HasMany { return $this->hasMany(Passkey::class); }

    public function hasRole(string ...$keys): bool
    {
        return $this->roles->pluck('key')->intersect($keys)->isNotEmpty();
    }

    public function isHr(): bool { return $this->hasRole('admin', 'hr_director', 'hr_officer'); }

    /** platform | hr | employee | candidate — الواجهة بعد الدخول */
    public function portal(): string
    {
        if ($this->hasRole('platform_admin')) return 'platform';
        if ($this->hasRole(...self::ADMIN_ROLES)) return 'hr';
        if ($this->employee_id) return 'employee';
        return 'candidate';
    }

    public function displayName(string $lang = 'ar'): string
    {
        if ($this->employee) return $lang === 'en' ? $this->employee->name_en : $this->employee->name;
        return ($lang === 'en' ? $this->name_en : null) ?: $this->name ?: $this->email;
    }

    /** موقوف: حساب معطّل، أو موظف انتهت خدمته (M11 يمنع الدخول بعد الإغلاق)، أو شركته معلّقة على المنصة */
    public function blocked(): bool
    {
        return ! $this->active || $this->employee?->status === 'terminated' || $this->company?->status === 'suspended';
    }

    public function lockedMinutes(): int
    {
        return $this->locked_until && $this->locked_until->isFuture() ? (int) ceil(now()->diffInSeconds($this->locked_until) / 60) : 0;
    }

    /** ما تحتاجه الواجهة بعد الدخول */
    public function sessionPayload(): array
    {
        $this->loadMissing(['roles', 'employee.department', 'company']);
        $e = $this->employee;
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => ['ar' => $this->displayName('ar'), 'en' => $this->displayName('en')],
            'roles' => $this->roles->pluck('key')->values(),
            'portal' => $this->portal(),
            'must_change_password' => $this->must_change_password,
            'employee' => $e ? ['code' => $e->code, 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'dept' => ['ar' => $e->department->name_ar, 'en' => $e->department->name_en], 'gender' => $e->gender] : null,
            'company' => $this->company?->card(),
            // صلاحيات الوحدات للواجهة (الإخفاء فقط — الخادم يتحقق في كل endpoint)
            'perms' => (object) \App\Support\Perm::for($this),
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotice($token));
    }
}
