<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\MonthSheet;
use App\Models\MonthSheetRow;
use App\Models\ServiceRequest;
use App\Services\Payroll\Payroll;
use App\Services\Payroll\SheetReports;
use App\Support\Settings;
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
        $main = ['name' => $s->month, 'title' => "كشف الدوام والمستحقات — {$s->month} (تقديري)", 'rows' => [], 'head' => ['الرقم', 'الموظف', 'الإدارة', 'أيام العمل', 'الحضور', 'غياب', 'غياب مبرر', 'ناقص', 'إجازات', 'أيام بلا أجر', 'التأخير (د)', 'معفى (د)', 'الحسم (د)', 'الإضافي المسجل (د)', 'الإضافي المعتمد (د)',
            'الراتب', 'أجر الإضافي', 'مكافأة التقييم', 'حسم التأخير', 'حسم الغياب', 'حسم الإجازات', 'حسم المخالفات (أيام)', 'حسم المخالفات', 'التأمينات', 'السلفة', 'الصافي', 'العملة', 'الصافي بعملة الصرف', 'عملة الصرف', 'سعر الصرف']];
        foreach ($rows as $r) {
            [$d, $m, $p] = [$r['data'], $r['money'], $r['pay']];
            $main['rows'][] = ([$r['employee']['code'], $r['employee']['name']['ar'], $r['employee']['dept']['ar'] ?? '', $d['work_days'], $d['present'], $d['absent'], $d['justified_absent'], $d['incomplete'], $d['leave'], $d['leave_unpaid'],
                $d['late'], $d['exempt_min'], $d['deduct'], $d['ot_raw'], $d['ot_paid'], $m['base'], $m['ot_pay'], $m['bonus'] ?? 0, $m['late'], $m['absent'], $m['leave'], $d['discipline_days'] ?? 0, $m['discipline'] ?? 0, $m['insurance'], $m['advance'], $m['net'], $m['currency'], $p['net'] ?? '', $p['currency'] ?? '', $p['rate'] ?? '']);
        }
        // ملخص + فروقات + تحقق آلي في الملف نفسه (ملاحظة 72)
        $path = $this->xlsx([$main, ...SheetReports::extras($s, $this->payroll)]);
        Audit::record('payroll.export', $s, null, ['month' => $s->month, 'count' => count($rows)]);
        return response()->download($path, "UHR-sheet-{$s->month}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** عملة الصرف وتاريخه للكشف (ملاحظتا 73/78) */
    public function pay(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'pay_date' => ['required', 'date']]);
        $s = MonthSheet::findOrFail($id);
        $s = $this->payroll->setPay($s, $v['currency'] ?? null, \Illuminate\Support\Carbon::parse($v['pay_date'])->toDateString(), $request->user());
        return response()->json($this->payload($request, $s->month, $s->load(['calculator', 'approver', 'closer'])));
    }

    /** أسعار الصرف المؤرخة + إعداد عملة الصرف */
    public function rates(): JsonResponse
    {
        return response()->json(['data' => ExchangeRate::with('creator')->orderByDesc('date')->orderBy('from')->limit(200)->get()->map(fn (ExchangeRate $r) => [
            'id' => $r->id, 'date' => $r->date->toDateString(), 'from' => $r->from, 'to' => $r->to, 'rate' => $r->rate, 'note' => $r->note,
            'by' => $r->creator ? ['ar' => $r->creator->displayName('ar'), 'en' => $r->creator->displayName('en')] : null]),
            'settings' => Settings::merged('payroll_sheet'), 'defaults' => Settings::DEFAULTS['payroll_sheet'], 'currencies' => self::CURRENCIES,
            'used' => Employee::current()->distinct()->pluck('currency')->values()]);
    }

    public const CURRENCIES = ['USD', 'SYP', 'EUR', 'AED', 'SAR', 'TRY', 'GBP'];

    public function saveRate(Request $request): JsonResponse
    {
        $v = $request->validate(['date' => ['required', 'date'], 'from' => ['required', 'regex:/^[A-Z]{3}$/'], 'to' => ['required', 'regex:/^[A-Z]{3}$/', 'different:from'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'note' => ['nullable', 'string', 'max:200']]);
        $r = ExchangeRate::updateOrCreate(['date' => \Illuminate\Support\Carbon::parse($v['date'])->toDateString(), 'from' => $v['from'], 'to' => $v['to']],
            ['rate' => $v['rate'], 'note' => $v['note'] ?? null, 'created_by' => $request->user()->id]);
        return response()->json(['data' => ['id' => $r->id]], 201);
    }

    public function deleteRate(int $id): JsonResponse
    {
        ExchangeRate::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'pay_day' => ['required', 'integer', 'min:0', 'max:31'], 'diff_alert_pct' => ['required', 'numeric', 'min:1', 'max:100']]);
        $before = Settings::merged('payroll_sheet');
        $new = ['currency' => $v['currency'] ?? '', 'pay_day' => (int) $v['pay_day'], 'diff_alert_pct' => (float) $v['diff_alert_pct']];
        Settings::put('payroll_sheet', $new);
        Audit::record('settings.payroll_sheet', null, $before, $new);
        return response()->json(['settings' => $new]);
    }

    /**
     * قوالب التقارير (ملاحظة 75): bank كشف البنك، insurance كشف التأمينات، departments ملخص الإدارات، deductions الاقتطاعات، diff الفروقات.
     * بالمبالغ كاملة — يحتاج التحقق وإذناً على كل الرواتب، ويُسجَّل.
     */
    public function report(Request $request, int $id, string $kind): BinaryFileResponse
    {
        $s = MonthSheet::findOrFail($id);
        SalaryVault::requireAll($request->user());
        [$title, $sheets] = SheetReports::build($s, $kind, $this->payroll);
        $path = $this->xlsx($sheets);
        Audit::record('payroll.report', $s, null, ['month' => $s->month, 'kind' => $kind]);
        return response()->download($path, "UHR-{$kind}-{$s->month}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** @param list<array{name:string, title:string, head:list<string>, rows:list<list<mixed>>}> $sheets */
    private function xlsx(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pay').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        foreach ($sheets as $i => $sh) {
            $ws = $i === 0 ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $ws->setName(mb_substr($sh['name'], 0, 31))->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
            $w->addRow(Row::fromValues([$sh['title']], (new Style())->setFontBold()));
            $w->addRow(Row::fromValues($sh['head'], (new Style())->setFontBold()));
            foreach ($sh['rows'] as $r) $w->addRow(Row::fromValues($r));
        }
        $w->close();
        return $path;
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
                return ['id' => $r->id, 'employee' => \App\Services\Attendance\Attendance::person($r->employee), 'data' => $r->data, 'money' => $see ? $r->money : null, 'pay' => $see ? $r->pay : null, 'salary' => $see ? $r->salary : null, 'see' => $see];
            })->all();
    }

    /** مجاميع الكشف: الأعداد تبقى، والمبالغ null حين تكون الرواتب مقفلة */
    public static function totals(?array $t, bool $open): ?array
    {
        if ($t === null || $open) return $t;
        foreach (['base', 'ot_pay', 'deductions', 'advance', 'bonus', 'net', 'by_currency'] as $k) if (array_key_exists($k, $t)) $t[$k] = null;
        return $t;
    }

    private function payload(Request $request, string $month, ?MonthSheet $s): array
    {
        $u = $request->user();
        $who = fn ($x) => $x ? ['ar' => $x->displayName('ar'), 'en' => $x->displayName('en')] : null;
        $months = MonthSheet::orderByDesc('month')->limit(24)->get(['id', 'month', 'status'])->map(fn ($x) => ['month' => $x->month, 'status' => $x->status]);
        $rows = $s ? $this->rows($request, $s) : [];
        $visible = collect($rows)->keyBy(fn ($r) => $r['employee']['id']);
        $diff = $s ? $this->payroll->diff($s) : null;
        $seeAll = SalaryVault::others($u)['all'] ?? false;
        $people = $diff ? Employee::whereIn('id', array_keys($diff['rows']))->get()->keyBy('id') : collect();
        // الفروقات والتحقق: ضمن نطاق المستخدم فقط، والمبالغ لمن يراها
        $diffRows = $diff ? collect($diff['rows'])->filter(fn ($d) => $visible->has($d['employee_id']) || ($d['status'] === 'left' && $seeAll))
            ->map(function ($d) use ($visible, $people) {
                $see = $visible->get($d['employee_id'])['see'] ?? true;
                $e = $people->get($d['employee_id']);
                return ['employee' => $e ? \App\Services\Attendance\Attendance::person($e) : null] + ($see ? $d : ['employee_id' => $d['employee_id'], 'status' => $d['status'], 'alert' => $d['alert'], 'prev' => null, 'net' => null, 'delta' => null, 'pct' => null, 'changes' => [], 'currency' => null]);
            })->values()->all() : [];
        $checks = $s ? collect($this->payroll->checks($s, $diff))->filter(fn ($c) => $c['employee_id'] === null || $visible->has($c['employee_id']) || ($c['code'] === 'left' && $seeAll))
            ->map(fn ($c) => $c + ['employee' => $c['employee_id'] && $people->get($c['employee_id']) ? \App\Services\Attendance\Attendance::person($people->get($c['employee_id'])) : ($c['employee_id'] ? $visible->get($c['employee_id'])['employee'] ?? null : null)])->values()->all() : [];
        return ['month' => $month, 'current_month' => Payroll::currentMonth(), 'months' => $months,
            'masked' => ! ($all = (SalaryVault::others($u)['all'] ?? false)),
            'sheet' => $s ? ['id' => $s->id, 'month' => $s->month, 'status' => $s->status, 'pay_currency' => $s->pay_currency, 'pay_date' => $s->pay_date?->toDateString(), 'rates' => $s->rates, 'missing_rates' => Payroll::missingRates($s), 'totals' => self::totals($s->totals, $all), 'note' => $s->note,
                'calculated' => ['at' => $s->calculated_at?->toIso8601String(), 'by' => $who($s->calculator)], 'approved' => ['at' => $s->approved_at?->toIso8601String(), 'by' => $who($s->approver)],
                'closed' => ['at' => $s->closed_at?->toIso8601String(), 'by' => $who($s->closer)]] : null,
            'rows' => $rows, 'diff' => $diff ? ['prev' => $diff['prev'], 'alert_pct' => $diff['alert_pct'], 'rows' => $diffRows] : null, 'checks' => $checks, 'incomplete' => $s && $s->status !== 'closed' ? $this->payroll->incomplete($s) : [],
            'can' => ['edit' => Perm::can($u, 'payroll', 'edit'), 'decide' => Perm::can($u, 'payroll', 'decide'), 'export' => Perm::can($u, 'payroll', 'export'), 'attendance' => Perm::can($u, 'attendance', 'edit'), 'settings' => Perm::can($u, 'settings', 'edit')]];
    }
}
