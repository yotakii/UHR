<?php

namespace App\Services\Payroll;

use App\Exceptions\ApiException;
use App\Models\Advance;
use App\Models\Employee;
use App\Models\MonthSheet;
use App\Models\MonthSheetRow;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الكشف الشهري (D-01): مسودة ← محتسب ← معتمد (HR) ← مُغلق.
 * الاحتساب يعيد بناء صفوف كل الموظفين؛ الأيام الناقصة تمنع الإغلاق؛ الإغلاق يخصم السلف ويُبلغ كل موظف «كشفك جاهز».
 */
class Payroll
{
    public static function currentMonth(): string { return now()->format('Y-m'); }

    public function open(string $month, User $by): MonthSheet
    {
        if ($month > self::currentMonth()) throw ApiException::field('month', L::t('لا يُفتح كشف لشهر لم يبدأ.', 'A sheet cannot be opened for a month that has not started.'), 'month_future');
        if (MonthSheet::where('month', $month)->exists()) throw ApiException::field('month', L::t('الكشف مفتوح لهذا الشهر.', 'This month already has a sheet.'), 'sheet_exists');
        $cfg = Settings::merged('payroll_sheet');
        $s = MonthSheet::create(['month' => $month, 'status' => 'draft', 'pay_currency' => $cfg['currency'] ?: null, 'pay_date' => self::payDate($month)]);
        return $this->calculate($s, $by);
    }

    /** احتساب (أو إعادة احتساب) كل الصفوف — قبل الاعتماد فقط */
    public function calculate(MonthSheet $s, User $by): MonthSheet
    {
        if (! in_array($s->status, ['draft', 'calculated'], true)) throw new ApiException(L::t('الكشف معتمد — أرجعه للاحتساب أولاً.', 'The sheet is approved — send it back for calculation first.'), 'sheet_locked', 409);
        [$from, $to] = MonthCalc::range($s->month);
        return DB::transaction(function () use ($s, $by, $to) {
            $emps = Employee::current()->where('hire_date', '<=', $to)->orderBy('code')->get();
            $keep = [];
            foreach ($emps as $e) {
                $c = MonthCalc::compute($e, $s->month);
                MonthSheetRow::updateOrCreate(['month_sheet_id' => $s->id, 'employee_id' => $e->id], ['data' => $c['data'], 'money' => $c['money'], 'salary' => $e->salary, 'currency' => $e->currency]);
                $keep[] = $e->id;
            }
            MonthSheetRow::where('month_sheet_id', $s->id)->whereNotIn('employee_id', $keep)->delete();
            $this->convert($s);
            $s->update(['status' => 'calculated', 'calculated_at' => now(), 'calculated_by' => $by->id, 'totals' => $this->totals($s)]);
            Audit::record('payroll.calculated', $s, null, ['month' => $s->month, 'employees' => count($keep)]);
            if ($n = $s->totals['incomplete']) Notify::hr("كشف {$s->month}: {$n} يوم ناقص بلا خروج — صحّحها قبل الإغلاق", "Sheet {$s->month}: {$n} incomplete day(s) — correct them before closing", 'payroll');
            return $s->refresh();
        });
    }

    public function approve(MonthSheet $s, User $by, ?string $note): MonthSheet
    {
        if ($s->status !== 'calculated') throw new ApiException(L::t('يُعتمد الكشف بعد احتسابه.', 'The sheet is approved after it is calculated.'), 'sheet_state', 409);
        $this->requireRates($s);
        $s->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id, 'note' => $note ?: $s->note]);
        return $s->refresh();
    }

    /** إرجاع كشف معتمد للاحتساب — بسبب */
    public function reopen(MonthSheet $s, User $by, string $note): MonthSheet
    {
        if ($s->status !== 'approved') throw new ApiException(L::t('يُرجع الكشف المعتمد فقط — المغلق لا يُعدَّل.', 'Only an approved sheet can be sent back — a closed one cannot change.'), 'sheet_state', 409);
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (mb_strlen(trim($note)) < $min) throw ApiException::field('note', L::t("السبب إلزامي ({$min} أحرف على الأقل).", "A reason is required (at least {$min} characters)."), 'reason_required');
        $s->update(['status' => 'calculated', 'approved_at' => null, 'approved_by' => null, 'note' => trim($note)]);
        Audit::record('payroll.reopened', $s, null, ['note' => trim($note)]);
        return $s->refresh();
    }

    /** إغلاق: الشهر انتهى، الكشف معتمد، ولا أيام ناقصة — يخصم السلف ويُبلغ الموظفين */
    public function close(MonthSheet $s, User $by): MonthSheet
    {
        if ($s->status !== 'approved') throw new ApiException(L::t('يُغلق الكشف بعد اعتماده.', 'The sheet is closed after it is approved.'), 'sheet_state', 409);
        if ($s->month >= self::currentMonth()) throw new ApiException(L::t('يُغلق الكشف بعد انتهاء الشهر.', 'The sheet is closed after the month ends.'), 'month_open', 409);
        $open = $this->incomplete($s);
        if ($open) throw new ApiException(L::t('توجد أيام ناقصة بلا خروج — صحّحها من سجل الحضور ثم أعد الاحتساب: ', 'There are incomplete days without a check-out — correct them in attendance and recalculate: ').count($open), 'incomplete_days', 409);
        $this->requireRates($s);
        if (($s->totals['incomplete'] ?? 0) > 0) throw new ApiException(L::t('صُحّحت الأيام الناقصة بعد الاحتساب — أرجع الكشف وأعد احتسابه ثم اعتمده.', 'Incomplete days were corrected after calculating — send the sheet back, recalculate and approve it.'), 'sheet_stale', 409);
        return DB::transaction(function () use ($s, $by) {
            Advance::where('month', $s->month)->where('status', 'open')->update(['status' => 'deducted', 'month_sheet_id' => $s->id]);
            \App\Models\PerformanceAward::where('month', $s->month)->where('status', 'approved')->update(['status' => 'paid', 'paid_at' => now(), 'month_sheet_id' => $s->id]);
            $s->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);
            Audit::record('payroll.closed', $s, null, ['month' => $s->month, 'net' => $s->totals['net'] ?? null]);
            $users = User::whereIn('employee_id', MonthSheetRow::where('month_sheet_id', $s->id)->pluck('employee_id'))->pluck('id');
            foreach ($users as $uid) Notify::user($uid, "كشف دوامك ومستحقاتك لشهر {$s->month} جاهز", "Your attendance & pay sheet for {$s->month} is ready", 'payslip');
            return $s->refresh();
        });
    }

    /** تاريخ الصرف الافتراضي للشهر: يوم pay_day (أو آخر يوم إن كان 0 أو أكبر من طول الشهر) */
    public static function payDate(string $month): string
    {
        $day = (int) Settings::merged('payroll_sheet')['pay_day'];
        $d = Carbon::parse($month.'-01 12:00:00');
        return $day <= 0 || $day > $d->daysInMonth ? $d->endOfMonth()->toDateString() : $d->day($day)->toDateString();
    }

    /** عملة الصرف وتاريخه لكشف قبل اعتماده — يُعاد التحويل بسعر التاريخ الجديد */
    public function setPay(MonthSheet $s, ?string $currency, string $date, User $by): MonthSheet
    {
        if (! in_array($s->status, ['draft', 'calculated'], true)) throw new ApiException(L::t('الكشف معتمد — أرجعه للاحتساب أولاً.', 'The sheet is approved — send it back for calculation first.'), 'sheet_locked', 409);
        $before = ['currency' => $s->pay_currency, 'date' => $s->pay_date?->toDateString()];
        $s->update(['pay_currency' => $currency ?: null, 'pay_date' => $date]);
        $this->convert($s);
        $s->update(['totals' => $this->totals($s)]);
        Audit::record('payroll.pay_currency', $s, $before, ['currency' => $currency ?: null, 'date' => $date]);
        return $s->refresh();
    }

    public const AMOUNTS = ['base', 'ot_pay', 'late', 'absent', 'leave', 'insurance', 'advance', 'discipline', 'bonus', 'net', 'day', 'hour'];

    /** تحويل كل الصفوف إلى عملة الصرف بسعر تاريخ الصرف (آخر سعر بتاريخه أو قبله)؛ السعر الناقص يمنع الاعتماد */
    public function convert(MonthSheet $s): void
    {
        $rows = MonthSheetRow::where('month_sheet_id', $s->id)->get();
        if (! $s->pay_currency) {
            MonthSheetRow::where('month_sheet_id', $s->id)->update(['pay' => null]);
            $s->update(['rates' => null]);
            return;
        }
        $date = $s->pay_date?->toDateString() ?? self::payDate($s->month);
        $rates = [];
        foreach ($rows->pluck('currency')->unique() as $cur) $rates[$cur] = \App\Models\ExchangeRate::lookup($cur, $s->pay_currency, $date);
        foreach ($rows as $r) {
            $rt = $rates[$r->currency] ?? null;
            $pay = $rt ? array_map(fn ($k) => round((float) ($r->money[$k] ?? 0) * $rt['rate'], 2), array_combine(self::AMOUNTS, self::AMOUNTS)) + ['currency' => $s->pay_currency, 'rate' => round($rt['rate'], 6), 'from' => $r->currency] : null;
            $r->update(['pay' => $pay]);
        }
        $s->update(['rates' => $rates]);
    }

    /** العملات التي لا سعر صرف لها بتاريخ الصرف */
    public static function missingRates(MonthSheet $s): array
    {
        if (! $s->pay_currency) return [];
        return array_keys(array_filter($s->rates ?? [], fn ($r) => $r === null));
    }

    private function requireRates(MonthSheet $s): void
    {
        if ($miss = self::missingRates($s)) throw new ApiException(L::t('لا يوجد سعر صرف بتاريخ الصرف لـ: ', 'No exchange rate on the pay date for: ').implode('، ', $miss).' → '.$s->pay_currency, 'rate_missing', 409);
    }

    /** مبالغ الصف بعملة الصرف إن وُجدت، وإلا بعملة راتب الموظف */
    public static function amounts(MonthSheetRow $r, ?MonthSheet $s = null): array
    {
        return $r->pay ?: $r->money;
    }

    /**
     * الفروقات عن الكشف السابق (ملاحظة 72): لكل موظف الصافي السابق والحالي والفرق ونسبته والبنود التي تغيّرت،
     * وموظف جديد / غير موجود هذا الشهر — يُقارَن بالعملة نفسها فقط.
     */
    public function diff(MonthSheet $s): array
    {
        $prev = MonthSheet::where('month', '<', $s->month)->orderByDesc('month')->first();
        $alert = (float) Settings::merged('payroll_sheet')['diff_alert_pct'];
        $cur = MonthSheetRow::where('month_sheet_id', $s->id)->get()->keyBy('employee_id');
        $old = $prev ? MonthSheetRow::where('month_sheet_id', $prev->id)->get()->keyBy('employee_id') : collect();
        $keys = ['base', 'ot_pay', 'bonus', 'late', 'absent', 'leave', 'insurance', 'advance', 'discipline'];
        $out = [];
        foreach ($cur->keys()->merge($old->keys())->unique() as $id) {
            $a = $old->get($id); $b = $cur->get($id);
            $ma = $a ? self::amounts($a) : null; $mb = $b ? self::amounts($b) : null;
            $same = $ma && $mb && ($ma['currency'] ?? null) === ($mb['currency'] ?? null);
            $changes = [];
            if ($same) foreach ($keys as $k) { $d = round((float) ($mb[$k] ?? 0) - (float) ($ma[$k] ?? 0), 2); if (abs($d) >= 0.01) $changes[$k] = $d; }
            $delta = $same ? round($mb['net'] - $ma['net'], 2) : null;
            $pct = $same && abs($ma['net']) > 0.0001 ? round($delta / abs($ma['net']) * 100, 1) : null;
            $out[$id] = ['employee_id' => $id, 'status' => ! $a ? 'new' : (! $b ? 'left' : ($same ? 'same' : 'currency')),
                'prev' => $ma['net'] ?? null, 'net' => $mb['net'] ?? null, 'currency' => $mb['currency'] ?? $ma['currency'] ?? null, 'delta' => $delta, 'pct' => $pct, 'changes' => $changes,
                'alert' => ! $a || ! $b || ($pct !== null && abs($pct) >= $alert) || ($pct === null && $same && abs((float) $delta) > 0)];
        }
        return ['prev' => $prev?->month, 'alert_pct' => $alert, 'rows' => $out];
    }

    /**
     * التحقق الآلي قبل الاعتماد (ملاحظة 72): صافٍ صفري أو سالب، حساب بنكي ناقص، سعر صرف ناقص، أيام ناقصة، فرق كبير عن الشهر السابق.
     * @return list<array{code:string, employee_id:?int}>
     */
    public function checks(MonthSheet $s, ?array $diff = null): array
    {
        $diff ??= $this->diff($s);
        $out = [];
        foreach (self::missingRates($s) as $c) $out[] = ['code' => 'rate_missing', 'employee_id' => null, 'value' => $c];
        $rows = MonthSheetRow::with('employee:id,bank_account')->where('month_sheet_id', $s->id)->get();
        foreach ($rows as $r) {
            if (($r->money['net'] ?? 0) <= 0) $out[] = ['code' => 'net_zero', 'employee_id' => $r->employee_id, 'value' => null];
            if ($r->data['incomplete'] ?? 0) $out[] = ['code' => 'incomplete', 'employee_id' => $r->employee_id, 'value' => $r->data['incomplete']];
            if (! $r->employee?->bank_account) $out[] = ['code' => 'no_bank', 'employee_id' => $r->employee_id, 'value' => null];
            $d = $diff['rows'][$r->employee_id] ?? null;
            if ($diff['prev'] && $d && $d['alert'] && $d['status'] === 'same') $out[] = ['code' => 'big_change', 'employee_id' => $r->employee_id, 'value' => $d['pct']];
            if ($diff['prev'] && $d && $d['status'] === 'new') $out[] = ['code' => 'new', 'employee_id' => $r->employee_id, 'value' => null];
        }
        foreach ($diff['rows'] as $d) if ($d['status'] === 'left') $out[] = ['code' => 'left', 'employee_id' => $d['employee_id'], 'value' => null];
        return $out;
    }

    /** الأيام الناقصة حالياً (من الحضور الحي، لا من آخر احتساب) */
    public function incomplete(MonthSheet $s): array
    {
        $out = [];
        $emps = Employee::whereIn('id', MonthSheetRow::where('month_sheet_id', $s->id)->pluck('employee_id'))->get();
        foreach ($emps as $e) foreach (MonthCalc::compute($e, $s->month)['data']['incomplete_dates'] as $d) $out[] = ['employee_id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'date' => $d];
        return $out;
    }

    public function totals(MonthSheet $s): array
    {
        $rows = MonthSheetRow::where('month_sheet_id', $s->id)->get();
        // بعملة الصرف إن حُدّدت (الصفوف بلا سعر لا تُجمع)، وإلا بعملة الرواتب إن كانت واحدة
        $amt = fn ($r) => $s->pay_currency ? ($r->pay ?? []) : $r->money;
        $sum = fn (string $k) => round($rows->sum(fn ($r) => $amt($r)[$k] ?? 0), 2);
        $curs = $rows->pluck('currency')->unique()->values();
        $byCur = $curs->mapWithKeys(fn ($c) => [$c => round($rows->where('currency', $c)->sum(fn ($r) => $r->money['net'] ?? 0), 2)])->all();
        return ['employees' => $rows->count(), 'incomplete' => $rows->sum(fn ($r) => $r->data['incomplete']), 'absent' => $rows->sum(fn ($r) => $r->data['absent']),
            'late' => $rows->sum(fn ($r) => $r->data['late']), 'deduct' => round($rows->sum(fn ($r) => $r->data['deduct']), 1), 'ot_paid' => $rows->sum(fn ($r) => $r->data['ot_paid']),
            'base' => $sum('base'), 'ot_pay' => $sum('ot_pay'), 'deductions' => round($sum('late') + $sum('absent') + $sum('leave') + $sum('insurance') + $sum('discipline'), 2), 'advance' => $sum('advance'), 'bonus' => $sum('bonus'), 'net' => $sum('net'),
            'currency' => $s->pay_currency ?: ($curs->count() === 1 ? $curs[0] : null), 'by_currency' => $byCur, 'missing_rates' => self::missingRates($s)];
    }

    /**
     * رصيد السلفة المتاح الآن (SRS §10.14): المستحق حتى تاريخه × advance_earned_ratio، بحد advance_max_ratio من الراتب،
     * ناقصاً سلف الشهر المعتمدة والمعلّقة؛ وadvance_per_month طلب بالشهر.
     */
    public static function advanceAvailable(Employee $e): array
    {
        $month = self::currentMonth();
        $p = Settings::policy('payroll');
        $today = now()->toDateString();
        [$from] = MonthCalc::range($month);
        $c = MonthCalc::compute($e, $month, $today);
        $elapsed = max(0, Carbon::parse(max($from, $e->hire_date?->toDateString() ?? $from))->diffInDays(Carbon::parse($today)) + 1);
        $m = $c['money'];
        $earned = max(0, $m['day'] * $elapsed - $m['late'] - $m['absent'] - $m['leave']);
        $taken = (float) Advance::where('employee_id', $e->id)->where('month', $month)->where('status', '!=', 'cancelled')->sum('amount');
        $pending = ServiceRequest::where('employee_id', $e->id)->where('kind', 'advance')->whereIn('status', \App\Models\LeaveRequest::PENDING);
        $count = Advance::where('employee_id', $e->id)->where('month', $month)->where('status', '!=', 'cancelled')->count() + (clone $pending)->count();
        $cap = min($earned * (float) $p['advance_earned_ratio'], (float) $e->salary * (float) $p['advance_max_ratio']);
        $available = $count >= (int) $p['advance_per_month'] ? 0 : max(0, floor($cap - $taken - (float) $pending->sum('amount')));
        return ['month' => $month, 'earned' => round($earned, 2), 'cap' => round($cap, 2), 'taken' => $taken, 'available' => $available, 'currency' => $e->currency,
            'per_month' => (int) $p['advance_per_month'], 'used_count' => $count];
    }
}
