<?php

namespace App\Services\Payroll;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\MonthSheet;
use App\Models\MonthSheetRow;
use App\Support\L;
use App\Support\Settings;

/**
 * قوالب تقارير الكشف الشهري (ملاحظة الاختبار 75) وأوراق المراجعة الإضافية في ملف التصدير (ملاحظة 72).
 * المبالغ بعملة الصرف إن حُدّدت للكشف، وإلا بعملة راتب كل موظف. كل ورقة: name, title, head, rows.
 */
class SheetReports
{
    public const KINDS = ['bank', 'insurance', 'departments', 'deductions', 'diff'];

    public const CHECKS = [
        'rate_missing' => 'لا يوجد سعر صرف بتاريخ الصرف',
        'net_zero' => 'الصافي صفر أو سالب',
        'incomplete' => 'أيام ناقصة بلا خروج',
        'no_bank' => 'لا يوجد رقم حساب بنكي',
        'big_change' => 'فرق كبير عن الشهر السابق',
        'new' => 'موظف جديد في الكشف',
        'left' => 'موظف لم يعد في الكشف',
    ];

    /** @return array{0: string, 1: list<array>} */
    public static function build(MonthSheet $s, string $kind, Payroll $payroll): array
    {
        if (! in_array($kind, self::KINDS, true)) throw new ApiException(L::t('تقرير غير معروف.', 'Unknown report.'), 'report_unknown', 404);
        $rows = self::rows($s);
        $sheet = match ($kind) {
            'bank' => self::bank($s, $rows),
            'insurance' => self::insurance($s, $rows),
            'departments' => self::departments($s, $rows),
            'deductions' => self::deductions($s, $rows),
            'diff' => self::diffSheet($s, $payroll),
        };
        return [$sheet['title'], [$sheet]];
    }

    /** الأوراق الإضافية في تصدير الكشف: الملخص، الفروقات، التحقق الآلي */
    public static function extras(MonthSheet $s, Payroll $payroll): array
    {
        $rows = self::rows($s);
        $t = $s->totals ?? [];
        $sum = ['name' => 'ملخص', 'title' => "ملخص كشف {$s->month}", 'head' => ['البند', 'القيمة'], 'rows' => [
            ['الموظفون', $t['employees'] ?? 0], ['أيام الغياب', $t['absent'] ?? 0], ['دقائق التأخير', $t['late'] ?? 0], ['دقائق الحسم', $t['deduct'] ?? 0], ['الإضافي المعتمد (د)', $t['ot_paid'] ?? 0],
            ['الرواتب', $t['base'] ?? 0], ['أجر الإضافي', $t['ot_pay'] ?? 0], ['المكافآت', $t['bonus'] ?? 0], ['الاقتطاعات', $t['deductions'] ?? 0], ['السلف', $t['advance'] ?? 0], ['الصافي', $t['net'] ?? 0],
            ['العملة', $t['currency'] ?? 'متعددة'], ['تاريخ الصرف', $s->pay_date?->toDateString() ?? ''],
            ...collect($s->rates ?? [])->map(fn ($r, $c) => ["سعر {$c} → {$s->pay_currency}", $r ? $r['rate'].' ('.$r['date'].')' : 'غير متوفر'])->values()->all(),
        ]];
        $dept = self::departments($s, $rows);
        $sum['rows'][] = ['', ''];
        $sum['rows'][] = $dept['head'];
        foreach ($dept['rows'] as $r) $sum['rows'][] = $r;
        $diff = $payroll->diff($s);
        $people = Employee::whereIn('id', array_keys($diff['rows']))->get()->keyBy('id');
        $chk = ['name' => 'التحقق', 'title' => 'التحقق الآلي — راجع هذه الصفوف قبل الاعتماد', 'head' => ['الملاحظة', 'الرقم', 'الموظف', 'القيمة'], 'rows' => []];
        foreach ($payroll->checks($s, $diff) as $c) {
            $e = $c['employee_id'] ? $people->get($c['employee_id']) ?? Employee::find($c['employee_id']) : null;
            $chk['rows'][] = [self::CHECKS[$c['code']] ?? $c['code'], $e?->code ?? '', $e?->name ?? '', $c['value'] ?? ''];
        }
        return [$sum, self::diffSheet($s, $payroll, $diff), $chk];
    }

    private static function rows(MonthSheet $s)
    {
        return MonthSheetRow::with('employee.department')->where('month_sheet_id', $s->id)->get()->sortBy(fn ($r) => $r->employee->code)->values();
    }

    private static function cur(MonthSheetRow $r): array { return Payroll::amounts($r); }

    private static function bank(MonthSheet $s, $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $m = self::cur($r);
            $out[] = [$r->employee->code, $r->employee->name, $r->employee->bank_name ?? '', $r->employee->bank_account ?? '', $m['net'] ?? 0, $m['currency'] ?? '', $r->employee->bank_account ? '' : 'لا يوجد حساب'];
        }
        return ['name' => 'البنك', 'title' => "كشف البنك — رواتب {$s->month} — تاريخ الصرف ".($s->pay_date?->toDateString() ?? ''), 'head' => ['الرقم', 'الموظف', 'البنك', 'رقم الحساب / IBAN', 'المبلغ', 'العملة', 'ملاحظة'], 'rows' => $out];
    }

    private static function insurance(MonthSheet $s, $rows): array
    {
        [, $to] = MonthCalc::range($s->month);
        $p = Settings::policy('payroll', $to);
        $out = [];
        foreach ($rows as $r) {
            $m = self::cur($r);
            $base = (float) ($m['base'] ?? 0);
            $out[] = [$r->employee->code, $r->employee->name, $r->employee->national_id ?? '', $r->employee->hire_date?->toDateString() ?? '', $base, round($base * (float) $p['insurance_base'], 2), (float) $p['insurance_rate'] * 100, $m['insurance'] ?? 0, $m['currency'] ?? ''];
        }
        return ['name' => 'التأمينات', 'title' => "كشف التأمينات الاجتماعية — {$s->month}", 'head' => ['الرقم', 'الموظف', 'الرقم الوطني', 'تاريخ المباشرة', 'الراتب', 'الأجر الخاضع', 'النسبة %', 'الاقتطاع', 'العملة'], 'rows' => $out];
    }

    private static function departments(MonthSheet $s, $rows): array
    {
        $out = [];
        foreach ($rows->groupBy(fn ($r) => ($r->employee->department?->name_ar ?? 'بلا إدارة').'|'.(self::cur($r)['currency'] ?? '')) as $key => $g) {
            [$name, $cur] = explode('|', $key);
            $sum = fn ($k) => round($g->sum(fn ($r) => self::cur($r)[$k] ?? 0), 2);
            $out[] = [$name, $g->count(), $sum('base'), $sum('ot_pay'), $sum('bonus'), round($sum('late') + $sum('absent') + $sum('leave') + $sum('insurance') + $sum('discipline'), 2), $sum('advance'), $sum('net'), $cur];
        }
        return ['name' => 'الإدارات', 'title' => "ملخص الإدارات — {$s->month}", 'head' => ['الإدارة', 'الموظفون', 'الرواتب', 'الإضافي', 'المكافآت', 'الاقتطاعات', 'السلف', 'الصافي', 'العملة'], 'rows' => $out];
    }

    private static function deductions(MonthSheet $s, $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $m = self::cur($r);
            $tot = round(($m['late'] ?? 0) + ($m['absent'] ?? 0) + ($m['leave'] ?? 0) + ($m['discipline'] ?? 0) + ($m['insurance'] ?? 0) + ($m['advance'] ?? 0), 2);
            if ($tot <= 0) continue;
            $out[] = [$r->employee->code, $r->employee->name, $m['late'] ?? 0, $m['absent'] ?? 0, $m['leave'] ?? 0, $m['discipline'] ?? 0, $m['insurance'] ?? 0, $m['advance'] ?? 0, $tot, $m['currency'] ?? ''];
        }
        return ['name' => 'الاقتطاعات', 'title' => "الاقتطاعات — {$s->month}", 'head' => ['الرقم', 'الموظف', 'التأخير', 'الغياب', 'إجازات بلا أجر', 'المخالفات', 'التأمينات', 'السلفة', 'المجموع', 'العملة'], 'rows' => $out];
    }

    public const COMPONENTS = ['base' => 'الراتب', 'ot_pay' => 'الإضافي', 'bonus' => 'المكافأة', 'late' => 'حسم التأخير', 'absent' => 'حسم الغياب', 'leave' => 'إجازات بلا أجر', 'insurance' => 'التأمينات', 'advance' => 'السلفة', 'discipline' => 'المخالفات'];

    private static function diffSheet(MonthSheet $s, Payroll $payroll, ?array $diff = null): array
    {
        $diff ??= $payroll->diff($s);
        $people = Employee::whereIn('id', array_keys($diff['rows']))->get()->keyBy('id');
        $st = ['new' => 'جديد', 'left' => 'غير موجود هذا الشهر', 'same' => '', 'currency' => 'تغيّرت العملة'];
        $out = [];
        foreach (collect($diff['rows'])->sortByDesc(fn ($d) => [$d['alert'], abs((float) $d['delta'])]) as $d) {
            $e = $people->get($d['employee_id']);
            $changes = collect($d['changes'])->map(fn ($v, $k) => (self::COMPONENTS[$k] ?? $k).' '.($v > 0 ? '+' : '').$v)->implode('، ');
            $out[] = [$e?->code ?? '', $e?->name ?? '', $st[$d['status']] ?? '', $d['prev'] ?? '', $d['net'] ?? '', $d['delta'] ?? '', $d['pct'] ?? '', $d['currency'] ?? '', $changes, $d['alert'] ? 'راجِع' : ''];
        }
        return ['name' => 'الفروقات', 'title' => $diff['prev'] ? "الفروقات عن كشف {$diff['prev']} — تنبيه عند ±{$diff['alert_pct']}%" : 'لا يوجد كشف سابق للمقارنة',
            'head' => ['الرقم', 'الموظف', 'الحالة', 'الصافي السابق', 'الصافي الحالي', 'الفرق', 'النسبة %', 'العملة', 'البنود المتغيرة', 'تنبيه'], 'rows' => $out];
    }
}
