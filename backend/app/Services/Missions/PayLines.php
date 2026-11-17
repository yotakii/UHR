<?php

namespace App\Services\Missions;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\MonthSheet;
use App\Models\PayLine;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Support\Carbon;

/**
 * بنود الراتب (B.5): كل مبلغ من مهمة أو مكافأة يدخل الكشف كبند برمز عنصر من الدليل ومرجع مصدره.
 * الفترة = شهر الاعتماد النهائي، وما يُعتمد بعد يوم الإقفال يُرحَّل للشهر التالي (BR-TM-02)؛ سعر الصرف يُحفظ مع البند (BR-TM-08)؛
 * البند المرحَّل مقفل والتصحيح ببند معاكس (BR-TM-04).
 */
class PayLines
{
    /** فترة الراتب لتاريخ اعتماد: بعد يوم الإقفال أو شهر مغلق ← التالي */
    public static function period(?Carbon $at = null): string
    {
        $at ??= now();
        $cut = (int) Missions::cfg()['cutoff_day'];
        $m = $at->copy()->startOfMonth();
        if ($cut > 0 && $at->day > $cut) $m->addMonthNoOverflow();
        while (MonthSheet::where('month', $m->format('Y-m'))->where('status', 'closed')->exists()) $m->addMonthNoOverflow();
        return $m->format('Y-m');
    }

    /** هل تدخل فئة العامل الرواتب؟ (غير الموظفين: مستحقاتهم من عقودهم — POL-TM-01، AC-TM-04) */
    public static function inPayroll(Employee $e): bool
    {
        $cat = $e->worker_category_id ? \App\Models\CodeItem::find($e->worker_category_id) : null;
        return ! $cat || $cat->attr('payroll', true) !== false;
    }

    public static function add(Employee $e, string $code, float $amount, string $currency, string $source, ?int $sourceId, ?string $ref, array $label, ?string $period = null, ?User $by = null, ?string $note = null): ?PayLine
    {
        if (abs($amount) < 0.005) return null;
        $el = Codes::resolve('pay_elements', $code) ?? throw new ApiException(str_replace(':c', $code, L::t('عنصر الراتب :c غير موجود في دليل الترميز.', 'Pay element :c is not in the codebook.')), 'element_missing', 409);
        $rate = 1.0;
        if ($currency !== $e->currency) {
            $r = ExchangeRate::lookup($currency, $e->currency, now()->toDateString())
                ?? throw new ApiException(str_replace([':a', ':b'], [$currency, $e->currency], L::t('لا يوجد سعر صرف معتمد من :a إلى :b — أضفه في الكشف الشهري أولاً.', 'No approved exchange rate from :a to :b — add it in the monthly sheet first.')), 'rate_missing', 409);
            $rate = (float) $r['rate'];
        }
        Codes::markUsed($el->id);
        return PayLine::create(['employee_id' => $e->id, 'element_id' => $el->id, 'element_code' => $el->code, 'kind' => $el->attr('kind', str_starts_with($el->code, 'D') ? 'deduction' : 'earning'),
            'amount' => round($amount, 2), 'currency' => $currency, 'fx_rate' => $rate, 'amount_pay' => round($amount * $rate, 2), 'pay_currency' => $e->currency,
            'period' => $period ?? self::period(), 'source_type' => $source, 'source_id' => $sourceId, 'ref' => $ref, 'label_ar' => $label[0] ?? $el->name_ar, 'label_en' => $label[1] ?? $el->name_en,
            'channel' => $el->attr('channel', 'payroll'), 'status' => 'pending', 'created_by' => $by?->id ?? auth()->id(), 'note' => $note]);
    }

    /** تصحيح بند مرحَّل ببند معاكس في الفترة المفتوحة (BR-TM-04) */
    public function reverse(PayLine $l, User $by, string $reason): PayLine
    {
        if ($l->status === 'reversed' || $l->reversal_of) throw new ApiException(L::t('البند معكوس سابقاً.', 'This line was already reversed.'), 'already_reversed', 409);
        if (mb_strlen(trim($reason)) < 5) throw ApiException::field('reason', L::t('اكتب سبب التصحيح.', 'Write the reason.'), 'reason');
        $n = PayLine::create(['employee_id' => $l->employee_id, 'element_id' => $l->element_id, 'element_code' => $l->element_code, 'kind' => $l->kind, 'amount' => -$l->amount, 'currency' => $l->currency,
            'fx_rate' => $l->fx_rate, 'amount_pay' => -$l->amount_pay, 'pay_currency' => $l->pay_currency, 'period' => self::period(), 'source_type' => 'reversal', 'source_id' => $l->id, 'ref' => $l->ref,
            'label_ar' => 'تصحيح: '.$l->label_ar, 'label_en' => 'Correction: '.$l->label_en, 'channel' => $l->channel, 'status' => 'pending', 'reversal_of' => $l->id, 'note' => trim($reason), 'created_by' => $by->id]);
        if ($l->status === 'pending') $l->update(['status' => 'reversed']);
        Audit::record('payline.reversed', $l, null, ['reversal' => $n->id, 'reason' => $reason]);
        return $n;
    }

    /** مجموع بنود شهر لموظف (للكشف): الاستحقاقات، الاستقطاعات، والتفاصيل للقسيمة */
    public static function forMonth(Employee $e, string $month): array
    {
        $rows = PayLine::where('employee_id', $e->id)->where('period', $month)->whereIn('status', ['pending', 'posted'])->where('channel', 'payroll')->orderBy('id')->get();
        $earn = round((float) $rows->where('kind', 'earning')->sum('amount_pay'), 2); $ded = round((float) $rows->where('kind', 'deduction')->sum('amount_pay'), 2);
        $info = PayLine::where('employee_id', $e->id)->where('period', $month)->whereIn('status', ['pending', 'posted'])->orderBy('id')->get();
        return ['earn' => $earn, 'ded' => $ded, 'lines' => $info->map(fn ($l) => ['code' => $l->element_code, 'ar' => $l->label_ar, 'en' => $l->label_en, 'kind' => $l->kind, 'amount' => $l->amount_pay,
            'currency' => $l->pay_currency, 'ref' => $l->ref, 'channel' => $l->channel])->values()->all()];
    }

    /** عند إقفال الكشف: البنود مرحّلة ومقفلة، والمهمات التي رُحّلت كل بنودها مغلقة مالياً */
    public static function postMonth(MonthSheet $s): void
    {
        $ids = PayLine::where('period', $s->month)->where('status', 'pending')->pluck('source_id', 'id');
        PayLine::where('period', $s->month)->where('status', 'pending')->update(['status' => 'posted', 'month_sheet_id' => $s->id]);
        $missions = PayLine::where('period', $s->month)->where('source_type', 'mission')->distinct()->pluck('source_id');
        foreach (\App\Models\Mission::whereIn('id', $missions)->where('status', 'posted')->get() as $m)
            if (! PayLine::where('source_type', 'mission')->where('source_id', $m->id)->where('status', 'pending')->exists()) $m->update(['status' => 'closed', 'closed_at' => now()]);
    }

    public static function row(PayLine $l, bool $show): array
    {
        return ['id' => $l->id, 'code' => $l->element_code, 'ar' => $l->label_ar, 'en' => $l->label_en, 'kind' => $l->kind, 'period' => $l->period, 'status' => $l->status, 'channel' => $l->channel,
            'ref' => $l->ref, 'source_type' => $l->source_type, 'source_id' => $l->source_id, 'reversal_of' => $l->reversal_of, 'note' => $l->note,
            'amount' => $show ? $l->amount : null, 'currency' => $l->currency, 'fx_rate' => $show ? $l->fx_rate : null, 'amount_pay' => $show ? $l->amount_pay : null, 'pay_currency' => $l->pay_currency, 'masked' => ! $show];
    }
}
