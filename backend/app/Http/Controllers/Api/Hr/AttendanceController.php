<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\Attendance;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M08 — الحضور والدوام: السجل اليومي، ملخص الفترة، التصحيح اليدوي، التصدير — بنطاق صلاحية «الدوام» */
class AttendanceController extends Controller
{
    public function __construct(private Attendance $att) {}

    public function daily(Request $request): JsonResponse
    {
        $f = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'dept' => ['nullable', 'integer'], 'branch' => ['nullable', 'integer']]);
        return response()->json($this->att->daily($this->scope($request), $f['date'] ?? now()->toDateString(), $f['dept'] ?? null) + $this->meta($request));
    }

    public function period(Request $request): JsonResponse
    {
        [$from, $to, $dept] = $this->range($request);
        $p = $this->att->period($this->scope($request), $from, $to, $dept);
        // ملاحظة الاختبار 23: إحصائية الفترة لكل الموظفين، وربطها بكشف الشهر إن كانت الفترة ضمن شهر واحد
        $rows = collect($p['rows']);
        $sum = fn ($k) => $rows->sum($k);
        $expected = $sum('present') + $sum('absent') + $sum('incomplete');
        $p['totals'] = ['employees' => $rows->count(), 'present' => $sum('present'), 'absent' => $sum('absent'), 'incomplete' => $sum('incomplete'), 'leave' => $sum('leave'),
            'late_days' => $sum('late_days'), 'late' => $sum('late'), 'deduct' => round($sum('deduct'), 1), 'ot' => $sum('ot'), 'rate' => $expected ? (int) round(100 * $sum('present') / $expected) : null];
        $month = substr($from, 0, 7) === substr($to, 0, 7) ? substr($from, 0, 7) : null;
        $sheet = $month ? \App\Models\MonthSheet::where('month', $month)->first() : null;
        $p['payroll'] = ['month' => $month, 'sheet' => $sheet ? ['id' => $sheet->id, 'status' => $sheet->status] : null, 'can' => Perm::can($request->user(), 'payroll')];
        return response()->json($p + $this->meta($request));
    }

    /** اتجاه الحضور الأسبوعي (ملاحظة 31): لكل أسبوع نسبة الحضور، أيام التأخير، الغياب — بالإدارة أو الفرع */
    public function trend(Request $request): JsonResponse
    {
        $f = $request->validate(['weeks' => ['nullable', 'integer', 'between:2,26'], 'dept' => ['nullable', 'integer'], 'branch' => ['nullable', 'integer']]);
        $n = (int) ($f['weeks'] ?? 8);
        $today = now()->toDateString();
        $start = Carbon::parse($today)->startOfWeek(Carbon::SATURDAY)->subWeeks($n - 1);
        $emps = $this->scope($request)->current()->when($f['dept'] ?? null, fn ($q, $d) => $q->where('department_id', $d))->get();
        $days = \App\Models\AttendanceDay::whereIn('employee_id', $emps->pluck('id'))->whereBetween('date', [$start->toDateString(), $today])->get()->groupBy(fn ($d) => $d->employee_id.'|'.$d->date->toDateString());
        $leave = Attendance::onLeave($emps->pluck('id'), $start->toDateString(), $today);
        $since = (string) (\App\Support\Settings::policy('payroll')['start_date'] ?? '');   // بدء احتساب الدوام في النظام — ما قبله لا يُعد غياباً
        $out = [];
        for ($w = 0; $w < $n; $w++) {
            $ws = $start->copy()->addWeeks($w); $we = $ws->copy()->addDays(6);
            $t = ['from' => $ws->toDateString(), 'to' => min($we->toDateString(), $today), 'expected' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
            foreach ($emps as $e) for ($d = $ws->copy(); $d->lte($we) && $d->toDateString() <= $today; $d->addDay()) {
                $date = $d->toDateString();
                if (($since && $date < $since) || ($e->hire_date && $e->hire_date->toDateString() > $date) || isset($leave[$e->id][$date])) continue;
                $r = $days->get($e->id.'|'.$date)?->first();
                $c = \App\Services\Attendance\DayCalc::day($date, $r?->in_at, $r?->out_at, $e);
                if ($c['rest']) continue;
                $t['expected']++;
                if ($r?->in_at) { $t['present']++; if ($c['late'] > 0 && ! $r->justified) $t['late']++; }
                elseif ($date < $today && ! $r?->justified) $t['absent']++;
            }
            $t['rate'] = $t['expected'] ? (int) round(100 * $t['present'] / $t['expected']) : null;
            $out[] = $t;
        }
        return response()->json(['data' => $out]);
    }

    public function correct(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'in' => ['nullable', 'date_format:H:i'], 'out' => ['nullable', 'date_format:H:i'], 'note' => ['required', 'string', 'min:5', 'max:500']]);
        $e = Directory::scoped($request->user(), 'attendance')->findOrFail($id);
        $day = $this->att->correct($e, $d['date'], $d['in'] ?? null, $d['out'] ?? null, trim($d['note']), $request->user());
        $e->load('department');
        return response()->json(['data' => ['employee' => Attendance::person($e)] + Attendance::row($e, $d['date'], $day, false)]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$from, $to, $dept] = $this->range($request);
        $rows = $this->att->period($this->scope($request), $from, $to, $dept)['rows'];
        $path = tempnam(sys_get_temp_dir(), 'att').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('Attendance')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $w->addRow(Row::fromValues(["من {$from} إلى {$to}"], (new Style())->setFontBold()));
        $w->addRow(Row::fromValues(['الرقم', 'الموظف', 'الإدارة', 'أيام الحضور', 'الغياب', 'أيام ناقصة', 'الإجازات', 'أيام التأخير', 'مجموع التأخير (د)', 'دقائق الحسم', 'الإضافي (د)'], (new Style())->setFontBold()));
        foreach ($rows as $r) $w->addRow(Row::fromValues([$r['employee']['code'], $r['employee']['name']['ar'], $r['employee']['dept']['ar'] ?? '', $r['present'], $r['absent'], $r['incomplete'], $r['leave'], $r['late_days'], $r['late'], $r['deduct'], $r['ot']]));
        $w->close();
        Audit::record('attendance.export', null, null, ['from' => $from, 'to' => $to, 'dept' => $dept, 'count' => count($rows)]);
        return response()->download($path, "UHR-attendance-{$from}-{$to}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    private function range(Request $request): array
    {
        $f = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'dept' => ['nullable', 'integer'], 'branch' => ['nullable', 'integer']]);
        $to = $f['to'] ?? now()->toDateString();
        $from = $f['from'] ?? Carbon::parse($to)->subDays(13)->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'Range too long');
        return [$from, $to, $f['dept'] ?? null];
    }

    /** نطاق الموظفين حسب صلاحية الحضور + فلتر الفرع (ملاحظة 31) */
    private function scope(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = Directory::scoped($request->user(), 'attendance');
        if ($b = $request->integer('branch')) $q->where('location_id', $b);
        return $q;
    }

    private function meta(Request $request): array
    {
        $u = $request->user();
        return ['depts' => Department::where('active', true)->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'branches' => \App\Models\Location::where('active', true)->whereIn('id', \App\Models\Employee::current()->select('location_id'))->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'can' => ['edit' => Perm::can($u, 'attendance', 'edit'), 'export' => Perm::can($u, 'attendance', 'export'), 'devices' => Perm::can($u, 'attendance', 'edit'), 'policies' => Perm::can($u, 'policies')]];
    }
}
