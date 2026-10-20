<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * جدول «آخر N يوم عمل» في ملف الموظف — AttendanceTable في الديمو: يوم الراحة يظهر فقط إذا فيه تسجيل،
 * والحالة من Attendance::status (قبل المباشرة، إجازة، عطلة، لم يسجل بعد، غياب، ناقص، تأخير مبرر، متأخر، حاضر).
 */
class RecentDays
{
    public static function rows(Employee $e, int $n = 14, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $from = Carbon::parse($today)->subDays(45)->toDateString();
        $days = AttendanceDay::where('employee_id', $e->id)->whereBetween('date', [$from, $today])->get()->keyBy(fn ($d) => $d->date->toDateString());
        $leave = Attendance::onLeave(collect([$e->id]), $from, $today)[$e->id] ?? [];
        $out = [];
        $d = Carbon::parse($today.' 12:00:00');
        for ($i = 0; count($out) < $n && $i < 40; $i++, $d->subDay()) {
            $date = $d->toDateString();
            $r = $days->get($date);
            if (! $r && DayCalc::weekend($date, $e->department_id)) continue;
            $out[] = Attendance::row($e, $date, $r, isset($leave[$date]));
        }
        return $out;
    }
}
