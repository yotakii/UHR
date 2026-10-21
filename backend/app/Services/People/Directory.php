<?php

namespace App\Services\People;

use App\Models\Employee;
use App\Models\User;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** M07 — دليل الموظفين: نطاق الرؤية حسب الصلاحية، الاستحقاق السنوي، ومدة الخدمة */
class Directory
{
    /** نطاق «الموظفين» للمستخدم: own خاصته، team فريقه (مرؤوسوه المباشرون)، all الكل (SRS §10.2) */
    public static function scoped(User $u, string $module = 'employees'): Builder
    {
        $q = Employee::query();
        return match (Perm::scope($u, $module)) {
            'all' => $q,
            'team' => $q->where(fn ($w) => $w->where('manager_id', $u->employee_id)->orWhere('id', $u->employee_id)),
            default => $q->where('id', $u->employee_id ?? 0),
        };
    }

    public static function visible(User $u, int $employeeId, string $module = 'employees'): bool
    {
        return Perm::can($u, $module) && self::scoped($u, $module)->whereKey($employeeId)->exists();
    }

    /**
     * الاستحقاق السنوي — annualEntitlement في الديمو بقيم سياسة annual_leave النافذة (HR.01-01):
     * senior لمن خدم senior_years أو بلغ senior_age، standard بعد standard_years، وإلا first_year (بالنسبة لأقل من سنة إن prorate_first_year).
     */
    public static function entitlement(Employee $e, ?string $on = null): float
    {
        $on ??= now()->toDateString();
        $p = Settings::policy('annual_leave', $on);
        $years = self::days($e->hire_date?->toDateString(), $on) / 365;
        $age = $e->dob ? self::days($e->dob->toDateString(), $on) / 365 : 0;
        if ($years >= $p['senior_years'] || $age >= $p['senior_age']) return (float) $p['senior'];
        if ($years >= $p['standard_years']) return (float) $p['standard'];
        if ($p['prorate_first_year'] && $years < 1) return max(0, floor($p['first_year'] * $years * 2) / 2);   // لأقرب نصف يوم
        return (float) $p['first_year'];
    }

    /** أيام الخدمة حتى اليوم (سالبة = لم يباشر بعد) */
    public static function tenureDays(Employee $e): int
    {
        return self::days($e->hire_date?->toDateString(), now()->toDateString());
    }

    private static function days(?string $from, string $to): int
    {
        return $from ? (int) Carbon::parse($from.' 12:00:00')->diffInDays(Carbon::parse($to.' 12:00:00'), false) : 0;
    }
}
