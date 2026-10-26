<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\MonthSheet;
use App\Models\MonthSheetRow;
use App\Models\ServiceRequest;
use App\Services\Payroll\Payroll;
use App\Services\People\Directory;
use App\Services\People\ServiceDesk;
use App\Services\Security\SalaryVault;
use App\Support\Audit;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M08/M14 — الكشف الشهري للدوام والمستحقات (D-01) بنطاق صلاحية «الرواتب والسلف»؛ المبالغ تقديرية حتى التكامل مع ZERP */
class PayrollController extends Controller
{
    public function __construct(private Payroll $payroll) {}

    public function show(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? Payroll::currentMonth();
        $s = MonthSheet::with(['calculator', 'approver', 'closer'])->where('month', $month)->first();
        return response()->json($this->payload($request, $month, $s));
    }

    public function open(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $s = $this->payroll->open($month, $request->user());
        return response()->json($this->payload($request, $month, $s->load(['calculator', 'approver', 'closer'])), 201);
    }

    public function act(Request $request, int $id, string $action): JsonResponse
    {
        $s = MonthSheet::findOrFail($id);
        $note = trim((string) $request->input('note', ''));
        $u = $request->user();
        abort_unless($action === 'calculate' ? Perm::can($u, 'payroll', 'edit') : Perm::can($u, 'payroll', 'decide'), 403);
        $s = match ($action) {
            'calculate' => $this->payroll->calculate($s, $u),
            'approve' => $this->payroll->approve($s, $u, $note ?: null),
            'reopen' => $this->payroll->reopen($s, $u, $note),
            'close' => $this->payroll->close($s, $u),
        };
        return response()->json($this->payload($request, $s->month, $s->load(['calculator', 'approver', 'closer'])));
    }

    public function export(Request $request, int $id): BinaryFileResponse
    {
        $s = MonthSheet::findOrFail($id);
        SalaryVault::requireAll($request->user());
        $rows = $this->rows($request, $s);
        $path = tempnam(sys_get_temp_dir(), 'pay').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName($s->month)->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $w->addRow(Row::fromValues(["كشف الدوام والمستحقات — {$s->month} (تقديري)"], (new Style())->setFontBold()));
        $w->addRow(Row::fromValues(['الرقم', 'الموظف', 'الإدارة', 'أيام العمل', 'الحضور', 'غياب', 'غياب مبرر', 'ناقص', 'إجازات', 'أيام بلا أجر', 'التأخير (د)', 'معفى (د)', 'الحسم (د)', 'الإضافي المسجل (د)', 'الإضافي المعتمد (د)',
            'الراتب', 'أجر الإضافي', 'مكافأة التقييم', 'حسم التأخير', 'حسم الغياب', 'حسم الإجازات', 'حسم المخالفات (أيام)', 'حسم المخالفات', 'التأمينات', 'السلفة', 'الصافي', 'العملة'], (new Style())->setFontBold()));
        foreach ($rows as $r) {
            [$d, $m] = [$r['data'], $r['money']];
            $w->addRow(Row::fromValues([$r['employee']['code'], $r['employee']['name']['ar'], $r['employee']['dept']['ar'] ?? '', $d['work_days'], $d['present'], $d['absent'], $d['justified_absent'], $d['incomplete'], $d['leave'], $d['leave_unpaid'],
                $d['late'], $d['exempt_min'], $d['deduct'], $d['ot_raw'], $d['ot_paid'], $m['base'], $m['ot_pay'], $m['bonus'] ?? 0, $m['late'], $m['absent'], $m['leave'], $d['discipline_days'] ?? 0, $m['discipline'] ?? 0, $m['insurance'], $m['advance'], $m['net'], $m['currency']]));
        }
        $w->close();
        Audit::record('payroll.export', $s, null, ['month' => $s->month, 'count' => count($rows)]);
        return response()->download($path, "UHR-sheet-{$s->month}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** شهادة صادرة (شهادة راتب / عمل) للطباعة من الموارد البشرية */
    public function certificate(Request $request, int $id): JsonResponse
    {
        $s = ServiceRequest::with(['employee', 'issuer.employee'])->whereNotNull('ref_no')->whereIn('employee_id', Directory::scoped($request->user(), 'leaves')->select('id'))->findOrFail($id);
        return response()->json(['data' => ServiceDesk::certificate($s, SalaryVault::sees($request->user(), $s->employee))]);
    }

    private function rows(Request $request, MonthSheet $s): array
    {
        $ids = Directory::scoped($request->user(), 'payroll')->select('id');
        $u = $request->user(); $others = SalaryVault::others($u);
        return MonthSheetRow::with('employee.department')->where('month_sheet_id', $s->id)->whereIn('employee_id', $ids)->get()->sortBy(fn ($r) => $r->employee->code)->values()
            ->map(function ($r) use ($u, $others) {
                $see = SalaryVault::sees($u, $r->employee, $others);
                return ['id' => $r->id, 'employee' => \App\Services\Attendance\Attendance::person($r->employee), 'data' => $r->data, 'money' => $see ? $r->money : null, 'salary' => $see ? $r->salary : null];
            })->all();
    }

    /** مجاميع الكشف: الأعداد تبقى، والمبالغ null حين تكون الرواتب مقفلة */
    public static function totals(?array $t, bool $open): ?array
    {
        if ($t === null || $open) return $t;
        foreach (['base', 'ot_pay', 'deductions', 'advance', 'bonus', 'net'] as $k) if (array_key_exists($k, $t)) $t[$k] = null;
        return $t;
    }

    private function payload(Request $request, string $month, ?MonthSheet $s): array
    {
        $u = $request->user();
        $who = fn ($x) => $x ? ['ar' => $x->displayName('ar'), 'en' => $x->displayName('en')] : null;
        $months = MonthSheet::orderByDesc('month')->limit(24)->get(['id', 'month', 'status'])->map(fn ($x) => ['month' => $x->month, 'status' => $x->status]);
        return ['month' => $month, 'current_month' => Payroll::currentMonth(), 'months' => $months,
            'masked' => ! ($all = (SalaryVault::others($u)['all'] ?? false)),
            'sheet' => $s ? ['id' => $s->id, 'month' => $s->month, 'status' => $s->status, 'totals' => self::totals($s->totals, $all), 'note' => $s->note,
                'calculated' => ['at' => $s->calculated_at?->toIso8601String(), 'by' => $who($s->calculator)], 'approved' => ['at' => $s->approved_at?->toIso8601String(), 'by' => $who($s->approver)],
                'closed' => ['at' => $s->closed_at?->toIso8601String(), 'by' => $who($s->closer)]] : null,
            'rows' => $s ? $this->rows($request, $s) : [], 'incomplete' => $s && $s->status !== 'closed' ? $this->payroll->incomplete($s) : [],
            'can' => ['edit' => Perm::can($u, 'payroll', 'edit'), 'decide' => Perm::can($u, 'payroll', 'decide'), 'export' => Perm::can($u, 'payroll', 'export'), 'attendance' => Perm::can($u, 'attendance', 'edit')]];
    }
}
