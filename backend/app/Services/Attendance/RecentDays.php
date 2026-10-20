<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * جدول «آخر N يوم عمل» في ملف الموظف — AttendanceTable في الديمو: يوم العطلة يظهر فقط إذا فيه تسجيل،
 * والحالة: قبل المباشرة، إجازة، عطلة رسمية، لم يسجل بعد، عطلة، غياب، تأخير مبرر، متأخر، حاضر.
 */
class RecentDays
{
    /** @return list<array{date: string, in: ?string, out: ?string, on_shift: bool, worked: int, late: int, ot: int, ot_rate: float, status: string}> */
    public static function rows(Employee $e, int $n = 14, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $from = Carbon::parse($today)->subDays(45)->toDateString();
        $days = AttendanceDay::where('employee_id', $e->id)->whereBetween('date', [$from, $today])->get()->keyBy(fn ($d) => $d->date->toDateString());
        $leaves = LeaveRequest::where('employee_id', $e->id)->where('status', 'approved')->whereNotIn('type', ['hourly', 'justify'])
            ->where('to', '>=', $from)->where('from', '<=', $today)->get(['from', 'to']);
        $holidays = Holiday::whereBetween('date', [$from, $today])->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $hire = $e->hire_date?->toDateString();

        $out = [];
        $d = Carbon::parse($today.' 12:00:00');
        for ($i = 0; count($out) < $n && $i < 40; $i++, $d->subDay()) {
            $date = $d->toDateString();
            $r = $days->get($date);
            if (DayCalc::weekend($date) && ! $r) continue;
            $c = DayCalc::day($date, $r?->in_at, $r?->out_at);
            $onLeave = $r?->status === 'leave' || $leaves->contains(fn ($l) => $l->from->toDateString() <= $date && $l->to->toDateString() >= $date);
            $status = match (true) {
                $hire && $hire > $date => 'before_start',
                $onLeave => 'leave',
                ! $r?->in_at && in_array($date, $holidays, true) => 'holiday',
                ! $r?->in_at => $date === $today ? 'not_in' : ($c['status'] === 'weekend' ? 'weekend' : 'absent'),
                $c['late'] > 0 => $r->justified ? 'justified' : 'late',
                default => 'present',
            };
            $out[] = ['date' => $date, 'in' => self::hm($r?->in_at), 'out' => self::hm($r?->out_at), 'on_shift' => $r?->in_at && ! $r->out_at && $date === $today,
                'worked' => $c['worked'], 'late' => $c['late'], 'ot' => $c['ot'], 'ot_rate' => $c['ot_rate'], 'status' => $status];
        }
        return $out;
    }

    private static function hm(?CarbonInterface $t): ?string
    {
        return $t?->copy()->setTimezone(config('app.timezone'))->format('H:i');
    }
}
