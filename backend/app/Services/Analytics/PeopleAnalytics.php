<?php

namespace App\Services\Analytics;

use App\Models\AttendanceDay;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Services\Attendance\Attendance;
use App\Services\Recruitment\Pipeline;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * التحليلات (SRS M13 analytics): «قراءات مأخوذة مباشرة من بيانات النظام — بدون إدخال يدوي».
 * القوى العاملة (سنوات الخدمة، كتلة الرواتب لمن يملك عرض الرواتب)، الحضور (خريطة الإدارات × آخر 10 أيام عمل)،
 * التوظيف (قمع المراحل، أنواع الطلبات)، والأداء (توزيع 9-Box لآخر دورة).
 */
class PeopleAnalytics
{
    public const TENURE = [[0, 1], [1, 3], [3, 5], [5, 10], [10, 99]];

    public function build(bool $withPay): array
    {
        $today = now()->toDateString();
        $active = Employee::with('department')->current()->get();
        $depts = Department::orderBy('id')->get();

        $tenure = array_map(fn ($b) => ['from' => $b[0], 'to' => $b[1], 'n' => $active->filter(function ($e) use ($b, $today) {
            if (! $e->hire_date) return false;
            $y = $e->hire_date->diffInDays(Carbon::parse($today)) / 365;
            return $y >= $b[0] && $y < $b[1];
        })->count()], self::TENURE);
        $tenure = array_values(array_filter($tenure, fn ($t, $i) => $i < 4 || $t['n'] > 0, ARRAY_FILTER_USE_BOTH));

        $headcount = $depts->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en, 'n' => $active->where('department_id', $d->id)->count()])->values();

        $pay = $withPay ? $depts->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en,
            'v' => round((float) $active->where('department_id', $d->id)->sum('salary'), 2)])->sortByDesc('v')->values() : null;

        // آخر 10 أيام عمل قبل اليوم
        $workDays = array_map('intval', (array) Settings::policy('work_days'));
        $days = [];
        for ($d = Carbon::parse($today)->subDay(); count($days) < 10 && $d->year > 2000; $d->subDay()) if (in_array($d->dayOfWeek, $workDays, true)) array_unshift($days, $d->toDateString());
        $att = AttendanceDay::whereIn('employee_id', $active->pluck('id'))->whereBetween('date', [$days[0] ?? $today, end($days) ?: $today])->get()
            ->groupBy(fn ($r) => $r->date->toDateString());
        $heat = $depts->map(function ($dep) use ($active, $days, $att) {
            $emps = $active->where('department_id', $dep->id);
            return ['id' => $dep->id, 'ar' => $dep->name_ar, 'en' => $dep->name_en, 'cells' => array_map(function ($day) use ($emps, $att) {
                $staff = $emps->filter(fn ($e) => $e->hire_date && $e->hire_date->toDateString() <= $day);
                $rows = ($att->get($day) ?? collect())->whereIn('employee_id', $staff->pluck('id'))->filter(fn ($r) => $r->in_at);
                $late = $rows->filter(fn ($r) => Attendance::row($staff->firstWhere('id', $r->employee_id), $day, $r, false)['late'] > 0)->count();
                return ['day' => $day, 'staff' => $staff->count(), 'present' => $rows->count(), 'rate' => $staff->count() ? round($rows->count() / $staff->count(), 3) : null, 'late' => $late];
            }, $days)];
        })->filter(fn ($r) => collect($r['cells'])->sum('staff') > 0)->values();

        $order = array_keys(Pipeline::STAGES);
        $stages = Candidate::where('stage', '!=', 'rejected')->selectRaw('stage, count(*) n')->groupBy('stage')->pluck('n', 'stage');
        $funnel = [];
        foreach (array_slice($order, 0, 6) as $i => $st) $funnel[] = ['stage' => $st, 'ar' => Pipeline::STAGES[$st][0], 'en' => Pipeline::STAGES[$st][1],
            'n' => (int) collect($order)->slice($i, 6 - $i)->sum(fn ($s) => $stages[$s] ?? 0)];
        $rejected = Candidate::where('stage', 'rejected')->count();

        $types = collect(Settings::policy('leave_types'))->keyBy('key');
        $leaves = LeaveRequest::selectRaw('type, count(*) n')->groupBy('type')->orderByDesc('n')->pluck('n', 'type')
            ->map(fn ($n, $k) => ['key' => $k, 'ar' => $types[$k]['ar'] ?? $k, 'en' => $types[$k]['en'] ?? $k, 'n' => (int) $n])->values();

        $cycle = \App\Models\PerformanceCycle::whereIn('status', ['open', 'closed'])->orderByDesc('starts_on')->orderByDesc('id')->first();
        $grid = [];
        if ($cycle) foreach (PerformanceReview::where('performance_cycle_id', $cycle->id)->whereNotNull('performance')->whereNotNull('potential')->get() as $r) {
            $k = $r->performance.$r->potential; $grid[$k] = ($grid[$k] ?? 0) + 1;
        }

        return ['as_of' => now()->toIso8601String(), 'headcount' => ['total' => $active->count(), 'by_dept' => $headcount], 'tenure' => $tenure, 'pay' => $pay,
            'currency' => $active->groupBy('currency')->map->count()->sortDesc()->keys()->first() ?? 'USD',
            'heat' => ['days' => $days, 'rows' => $heat], 'funnel' => $funnel, 'rejected' => $rejected, 'leaves' => $leaves,
            'performance' => $cycle ? ['cycle' => ['ar' => $cycle->name_ar, 'en' => $cycle->name_en, 'status' => $cycle->status], 'grid' => $grid] : null];
    }
}
