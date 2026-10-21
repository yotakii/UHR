<?php

namespace App\Models;

use App\Models\Concerns\HasEventLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use Concerns\BelongsToCompany;
    use HasEventLog;

    public const STATUSES = ['onboarding', 'probation', 'active', 'leave', 'resigning', 'terminated'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date:Y-m-d', 'dob' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
            'salary' => 'float', 'leave_used' => 'float',
        ];
    }

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function manager(): BelongsTo { return $this->belongsTo(Employee::class, 'manager_id'); }
    public function delegate(): BelongsTo { return $this->belongsTo(Employee::class, 'delegate_id'); }
    public function reports(): HasMany { return $this->hasMany(Employee::class, 'manager_id'); }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function user(): HasOne { return $this->hasOne(User::class); }
    public function onboarding(): HasOne { return $this->hasOne(Onboarding::class); }
    public function days(): HasMany { return $this->hasMany(AttendanceDay::class); }
    public function scans(): HasMany { return $this->hasMany(Scan::class); }
    public function leaves(): HasMany { return $this->hasMany(LeaveRequest::class); }
    public function services(): HasMany { return $this->hasMany(ServiceRequest::class); }
    public function assets(): HasMany { return $this->hasMany(Asset::class); }
    public function resignations(): HasMany { return $this->hasMany(Resignation::class); }
    public function referrals(): HasMany { return $this->hasMany(Referral::class); }
    public function redemptions(): HasMany { return $this->hasMany(Redemption::class); }
    public function documents(): HasMany { return $this->hasMany(EmployeeDocument::class); }

    public function scopeCurrent($q) { return $q->where('status', '!=', 'terminated'); }

    /** رقم بطاقة NFC فريد على مستوى المنصة (04:A1:…) */
    public static function newNfcUid(): string
    {
        do { $uid = implode(':', array_map(fn () => strtoupper(str_pad(dechex(random_int(0, 255)), 2, '0', STR_PAD_LEFT)), range(1, 5))); } while (self::allCompanies()->where('nfc_uid', $uid)->exists());
        return $uid;
    }

    public function displayName(string $lang): string { return $lang === 'en' ? $this->name_en : $this->name; }
}
