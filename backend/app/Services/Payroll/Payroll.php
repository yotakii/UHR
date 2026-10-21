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
        $s = MonthSheet::create(['month' => $month, 'status' => 'draft']);
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
            $s->update(['status' => 'calculated', 'calculated_at' => now(), 'calculated_by' => $by->id, 'totals' => $this->totals($s)]);
            Audit::record('payroll.calculated', $s, null, ['month' => $s->month, 'employees' => count($keep)]);
            if ($n = $s->totals['incomplete']) Notify::hr("كشف {$s->month}: {$n} يوم ناقص بلا خروج — صحّحها قبل الإغلاق", "Sheet {$s->month}: {$n} incomplete day(s) — correct them before closing", 'payroll');
            return $s->refresh();
        });
    }

    public function approve(MonthSheet $s, User $by, ?string $note): MonthSheet
    {
        if ($s->status !== 'calculated') throw new ApiException(L::t('يُعتمد الكشف بعد احتسابه.', 'The sheet is approved after it is calculated.'), 'sheet_state', 409);
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
        if (($s->totals['incomplete'] ?? 0) > 0) throw new ApiException(L::t('صُحّحت الأيام الناقصة بعد الاحتساب — أرجع الكشف وأعد احتسابه ثم اعتمده.', 'Incomplete days were corrected after calculating — send the sheet back, recalculate and approve it.'), 'sheet_stale', 409);
        return DB::transaction(function () use ($s, $by) {
            Advance::where('month', $s->month)->where('status', 'open')->update(['status' => 'deducted', 'month_sheet_id' => $s->id]);
            $s->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);
            Audit::record('payroll.closed', $s, null, ['month' => $s->month, 'net' => $s->totals['net'] ?? null]);
            $users = User::whereIn('employee_id', MonthSheetRow::where('month_sheet_id', $s->id)->pluck('employee_id'))->pluck('id');
            foreach ($users as $uid) Notify::user($uid, "كشف دوامك ومستحقاتك لشهر {$s->month} جاهز", "Your attendance & pay sheet for {$s->month} is ready", 'payslip');
            return $s->refresh();
        });
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
        $sum = fn (string $k) => round($rows->sum(fn ($r) => $r->money[$k] ?? 0), 2);
        return ['employees' => $rows->count(), 'incomplete' => $rows->sum(fn ($r) => $r->data['incomplete']), 'absent' => $rows->sum(fn ($r) => $r->data['absent']),
            'late' => $rows->sum(fn ($r) => $r->data['late']), 'deduct' => round($rows->sum(fn ($r) => $r->data['deduct']), 1), 'ot_paid' => $rows->sum(fn ($r) => $r->data['ot_paid']),
            'base' => $sum('base'), 'ot_pay' => $sum('ot_pay'), 'deductions' => round($sum('late') + $sum('absent') + $sum('leave') + $sum('insurance'), 2), 'advance' => $sum('advance'), 'net' => $sum('net')];
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
