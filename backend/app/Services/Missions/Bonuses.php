<?php

namespace App\Services\Missions;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\Bonus;
use App\Models\CodeItem;
use App\Models\Employee;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Services\People\Directory;
use App\Services\Security\SalaryVault;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * المكافآت كأنواع محكومة بالسياسة (B.4، WF-TM-03): النوع من الدليل يحدد طريقة الحساب والسقف وعنصر الراتب؛
 * فحص الأهلية والسقوف ومنع التكرار لنفس المرجع، الاعتماد بالمسار الموحد (سلسلة bonus + الإدارة العليا فوق السقف)، ثم بند راتب.
 */
class Bonuses
{
    public static function ref(): string { $y = now()->year; return "BNS-{$y}-".str_pad((string) Counters::next("bonus-{$y}"), 4, '0', STR_PAD_LEFT); }

    /** المبلغ حسب طريقة النوع: fixed/free/formula = مبلغ، percent = نسبة من الراتب، hours = ساعات × أجر */
    public static function amount(CodeItem $type, Employee $e, array $v): array
    {
        $method = $type->attr('method', 'free');
        if ($method === 'percent') {
            $pct = (float) ($v['percent'] ?? 0);
            if ($pct <= 0 || $pct > 100) throw ApiException::field('percent', L::t('النسبة بين 0 و100.', 'Percent is between 0 and 100.'), 'invalid');
            return [round((float) $e->salary * $pct / 100, 2), $e->currency, ['method' => 'percent', 'percent' => $pct]];
        }
        if ($method === 'hours') {
            $h = (float) ($v['hours'] ?? 0); $rate = (float) ($v['rate'] ?? 0);
            if (! empty($v['level'])) $rate = (float) (Missions::cfg()['trainer_rates'][$v['level']] ?? 0);
            if ($h <= 0 || $rate <= 0) throw ApiException::field('hours', L::t('أدخل الساعات والأجر.', 'Enter the hours and the rate.'), 'invalid');
            return [round($h * $rate, 2), strtoupper((string) ($v['currency'] ?? Missions::cfg()['trainer_currency'])), ['method' => 'hours', 'hours' => $h, 'rate' => $rate, 'level' => $v['level'] ?? null]];
        }
        $amt = round((float) ($v['amount'] ?? 0), 2);
        if ($amt <= 0) throw ApiException::field('amount', L::t('أدخل المبلغ.', 'Enter the amount.'), 'invalid');
        return [$amt, strtoupper((string) ($v['currency'] ?? $e->currency)), ['method' => $method]];
    }

    /** منح فردي أو جماعي (FR-TM-020) */
    public function give(User $u, array $v): array
    {
        $type = Codes::resolve('bonus_types', $v['type_id'] ?? null) ?? throw ApiException::field('type_id', L::t('اختر نوع المكافأة.', 'Choose the bonus type.'), 'invalid');
        $reason = trim((string) ($v['reason'] ?? ''));
        if (mb_strlen($reason) < 5) throw ApiException::field('reason', L::t('اكتب المبرر والمرجع.', 'Write the reason and the reference.'), 'reason');
        $ids = array_values(array_unique(array_map('intval', (array) ($v['employee_ids'] ?? []))));
        if (! $ids || count($ids) > 200) throw ApiException::field('employee_ids', L::t('اختر الموظفين.', 'Choose the employees.'), 'invalid');
        $emps = Directory::scoped($u, 'missions')->current()->whereIn('id', $ids)->get();
        if ($emps->count() !== count($ids)) throw ApiException::field('employee_ids', L::t('بعض الموظفين خارج نطاقك.', 'Some employees are outside your scope.'), 'out_of_scope');
        $made = [];
        DB::transaction(function () use ($u, $v, $type, $reason, $emps, &$made) {
            foreach ($emps as $e) $made[] = $this->create($u, $e, $type, $v, $reason, $v['source_type'] ?? 'manual', isset($v['source_id']) ? (int) $v['source_id'] : null);
        });
        return $made;
    }

    private function create(?User $u, Employee $e, CodeItem $type, array $v, string $reason, string $source, ?int $sourceId): Bonus
    {
        if (! PayLines::inPayroll($e)) throw ApiException::field('employee_ids', str_replace(':n', $e->name, L::t('فئة العامل :n لا تدخل الرواتب — مستحقاته من عقده.', ':n is not on payroll — paid under their contract.')), 'not_payroll');
        if ($sourceId && Bonus::where('employee_id', $e->id)->where('type_id', $type->id)->where('source_type', $source)->where('source_id', $sourceId)->whereNotIn('status', ['rejected', 'cancelled'])->exists())
            throw ApiException::field('source_id', L::t('مُنحت هذه المكافأة لنفس المرجع سابقاً.', 'This bonus was already granted for the same reference.'), 'duplicate_source');
        [$amount, $cur, $basis] = self::amount($type, $e, $v);
        $cap = (float) $type->attr('cap', 0);
        if ($type->attr('method') === 'free' && $cap > 0 && $amount > $cap && empty($v['over_cap'])) throw ApiException::field('amount', str_replace(':c', (string) $cap, L::t('المبلغ يتجاوز سقف النوع (:c) — فعّل «فوق السقف» ليُرفع للإدارة العليا.', 'The amount exceeds the type cap (:c) — tick «over the cap» to send it to top management.')), 'over_cap');
        $monthCap = (float) Missions::cfg()['bonus_monthly_cap'];
        $overMonth = $monthCap > 0 && (float) Bonus::where('employee_id', $e->id)->where('type_id', $type->id)->whereNotIn('status', ['rejected', 'cancelled'])->where('created_at', '>=', now()->startOfMonth())->sum('amount') + $amount > $monthCap;
        $b = Bonus::create(['ref_no' => self::ref(), 'employee_id' => $e->id, 'type_id' => $type->id, 'amount' => $amount, 'currency' => $cur, 'basis' => $basis + ['over_cap' => ($cap > 0 && $amount > $cap) || $overMonth],
            'reason' => $reason, 'source_type' => $source, 'source_id' => $sourceId, 'status' => 'pending_hr', 'requested_by' => $u?->id]);
        Codes::markUsed($type->id);
        $levels = Workflow::chain('bonus');
        if ($b->basis['over_cap']) $levels[] = Workflow::level(Missions::cfg()['bonus_over_cap_role']);
        app(Workflow::class)->start($b, $e, $levels, $u ?? User::where('company_id', $e->company_id)->whereHas('roles', fn ($r) => $r->where('key', 'admin'))->firstOrFail());
        return $b->refresh();
    }

    /** مكافأة آلية من حدث (إنجاز مهمة، الالتزام بالدوام) — تمر بالمراجعة كأي مكافأة (FR-TM-021) */
    public function auto(Employee $e, string $typeCode, float $amount, string $currency, string $source, ?int $sourceId, string $reason): ?Bonus
    {
        $type = Codes::resolve('bonus_types', $typeCode);
        if (! $type || ! PayLines::inPayroll($e)) return null;
        try { return $this->create(auth()->user(), $e, $type, ['amount' => $amount, 'currency' => $currency, 'over_cap' => true], $reason, $source, $sourceId); }
        catch (ApiException) { return null; }
    }

    public function decide(Bonus $b, User $u, bool $approve, ?string $note): Bonus
    {
        $b = app(Workflow::class)->decide($b, $u, $approve, $note, false, function (Bonus $x) use ($u) {
            $x->load(['employee', 'type']);
            $el = $x->type->attr('element') ?: 'E-220';
            PayLines::add($x->employee, $el, $x->amount, $x->currency, 'bonus', $x->id, $x->ref_no, [$x->type->name_ar.' — '.$x->ref_no, $x->type->name_en.' — '.$x->ref_no], null, $u);
            Audit::record('bonus.approved', $x, null, ['amount' => $x->amount, 'currency' => $x->currency]);
        });
        return $b->refresh();
    }

    /** مكافأة الالتزام بالدوام لشهر منتهٍ: بلا غياب ولا تأخر (bonuses:attendance) */
    public function attendance(string $month): int
    {
        $amt = (float) Missions::cfg()['attendance_bonus']; if ($amt <= 0) return 0;
        $from = $month.'-01'; $to = Carbon::parse($from)->endOfMonth()->toDateString(); $n = 0;
        foreach (Employee::current()->where('hire_date', '<=', $from)->get() as $e) {
            $calc = \App\Services\Payroll\MonthCalc::compute($e, $month, $to)['data'];
            if ($calc['absent'] > 0 || $calc['late_days'] > 0 || $calc['incomplete'] > 0) continue;
            if ($this->auto($e, 'BN-70', $amt, Missions::cfg()['attendance_bonus_currency'], 'attendance', (int) str_replace('-', '', $month), "مكافأة الالتزام بالدوام — {$month}")) $n++;
        }
        return $n;
    }

    public static function row(Bonus $b, User $viewer): array
    {
        $b->loadMissing(['employee', 'type', 'requester']);
        $show = SalaryVault::sees($viewer, $b->employee); $cur = Workflow::current($b);
        return ['kind' => 'bonus', 'id' => $b->id, 'ref_no' => $b->ref_no, 'type' => 'bonus', 'type_name' => ['ar' => $b->type->name_ar, 'en' => $b->type->name_en], 'bonus_type' => ['code' => $b->type->code, 'ar' => $b->type->name_ar, 'en' => $b->type->name_en],
            'employee' => \App\Http\Resources\RequestRow::person($b->employee), 'from' => $b->created_at->toDateString(), 'to' => $b->created_at->toDateString(), 'status' => $b->status, 'reason' => $b->reason,
            'amount' => $show ? $b->amount : null, 'currency' => $b->currency, 'masked' => ! $show, 'basis' => $b->basis, 'source_type' => $b->source_type, 'source_id' => $b->source_id, 'warnings' => [],
            'requested_by' => $b->requester ? ['ar' => $b->requester->displayName('ar'), 'en' => $b->requester->displayName('en')] : null,
            'created_at' => $b->created_at->toIso8601String(), 'steps' => Workflow::steps($b), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $b) : false, 'can_cancel' => false];
    }
}
