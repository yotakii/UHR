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
        $f = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'dept' => ['nullable', 'integer']]);
        $u = $request->user();
        return response()->json($this->att->daily(Directory::scoped($u, 'attendance'), $f['date'] ?? now()->toDateString(), $f['dept'] ?? null) + $this->meta($request));
    }

    public function period(Request $request): JsonResponse
    {
        [$from, $to, $dept] = $this->range($request);
        return response()->json($this->att->period(Directory::scoped($request->user(), 'attendance'), $from, $to, $dept) + $this->meta($request));
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
        $rows = $this->att->period(Directory::scoped($request->user(), 'attendance'), $from, $to, $dept)['rows'];
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
        $f = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'dept' => ['nullable', 'integer']]);
        $to = $f['to'] ?? now()->toDateString();
        $from = $f['from'] ?? Carbon::parse($to)->subDays(13)->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'Range too long');
        return [$from, $to, $f['dept'] ?? null];
    }

    private function meta(Request $request): array
    {
        $u = $request->user();
        return ['depts' => Department::where('active', true)->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'can' => ['edit' => Perm::can($u, 'attendance', 'edit'), 'export' => Perm::can($u, 'attendance', 'export'), 'devices' => Perm::can($u, 'attendance', 'edit'), 'policies' => Perm::can($u, 'policies')]];
    }
}
