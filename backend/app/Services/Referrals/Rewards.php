<?php

namespace App\Services\Referrals;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\PointLog;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * M12 الإحالات والمكافآت (SRS §10.9): رابط إحالة ثابت لكل موظف × شاغر، لوحة المتصدرين، متجر المكافآت،
 * والاستبدال (النقاط تُحجز فوراً ← اعتماد الموارد البشرية ينقص المخزون، والرفض بسبب يعيد النقاط).
 */
class Rewards
{
    public const KINDS = ['leave', 'food', 'gym', 'course', 'parking', 'gift'];

    /** رابط الإحالة: الشاغر مفتوح وظاهر خارج الشركة؛ الرمز ثابت (UHR1007-J12) */
    public function share(Employee $e, JobOpening $j): Referral
    {
        if ($j->status !== 'open' || $j->visibility === 'internal') throw new ApiException(L::t('هذا الشاغر غير منشور للإحالة الخارجية.', 'This opening is not published for external referrals.'), 'not_shareable', 409);
        if (in_array($e->status, ['resigning', 'terminated'], true)) throw new ApiException(L::t('الإحالة للموظفين على رأس عملهم.', 'Referrals are for current employees.'), 'not_eligible', 409);
        return Referral::firstOrCreate(['employee_id' => $e->id, 'job_opening_id' => $j->id], ['code' => strtoupper(str_replace('-', '', $e->code)).'-J'.$j->id, 'status' => 'shared']);
    }

    public static function link(Referral $r): string
    {
        $j = $r->jobOpening;
        return rtrim((string) config('app.frontend_url'), '/').'/jobs/'.Tenant::company()?->slug.'/'.$j->code.'?ref='.$r->code;
    }

    /** لوحة المتصدرين: مجموع النقاط المكتسبة (lifetime) وعدد التعيينات عبر الإحالة */
    public static function leaderboard(int $limit = 10): array
    {
        $hires = Referral::whereIn('status', ['hired', 'rewarded'])->selectRaw('employee_id, count(*) n')->groupBy('employee_id')->pluck('n', 'employee_id');
        return Employee::where('lifetime_points', '>', 0)->where('status', '!=', 'terminated')->orderByDesc('lifetime_points')->orderBy('name')->limit($limit)->get()
            ->map(fn ($e) => ['id' => $e->id, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'code' => $e->code, 'lifetime' => (int) $e->lifetime_points, 'hires' => (int) ($hires[$e->id] ?? 0)])->all();
    }

    public static function funnel(): array
    {
        return ['shared' => Referral::count(), 'applied' => Referral::whereNotNull('candidate_id')->count(), 'hired' => Referral::whereIn('status', ['hired', 'rewarded'])->count(),
            'points' => (int) PointLog::where('points', '>', 0)->whereNotNull('referral_id')->sum('points')];
    }

    // ── المتجر ──

    public function saveReward(?Reward $w, array $in): Reward
    {
        $d = Validator::make($in, ['title_ar' => ['required', 'string', 'min:2', 'max:190'], 'title_en' => ['required', 'string', 'min:2', 'max:190'], 'cost' => ['required', 'integer', 'between:1,1000000'],
            'stock' => ['required', 'integer', 'between:0,100000'], 'kind' => ['required', 'in:'.implode(',', self::KINDS)], 'active' => ['nullable', 'boolean']],
            [], ['title_ar' => L::t('اسم المكافأة', 'Reward name'), 'cost' => L::t('النقاط', 'Points'), 'stock' => L::t('المخزون', 'Stock')])->validate();
        $row = ['title_ar' => trim($d['title_ar']), 'title_en' => trim($d['title_en']), 'cost' => (int) $d['cost'], 'stock' => (int) $d['stock'], 'kind' => $d['kind'], 'active' => $d['active'] ?? true];
        if ($w) { $w->update($row); return $w; }
        return Reward::create($row);
    }

    // ── الاستبدال ──

    public function redeem(Employee $e, Reward $w): Redemption
    {
        return DB::transaction(function () use ($e, $w) {
            $e = Employee::whereKey($e->id)->lockForUpdate()->firstOrFail();
            $w = Reward::whereKey($w->id)->lockForUpdate()->firstOrFail();
            if (! $w->active || $w->stock < 1) throw new ApiException(L::t('هذه المكافأة غير متاحة الآن.', 'This reward is not available now.'), 'out_of_stock', 409);
            if ($e->points < $w->cost) throw new ApiException(L::t('رصيد نقاطك لا يكفي.', 'You do not have enough points.'), 'not_enough_points', 409);
            $r = Redemption::create(['employee_id' => $e->id, 'reward_id' => $w->id, 'cost' => $w->cost, 'status' => 'pending']);
            $e->update(['points' => $e->points - $w->cost]);
            PointLog::create(['employee_id' => $e->id, 'points' => -$w->cost, 'reason_ar' => "حجز نقاط لاستبدال: {$w->title_ar}", 'reason_en' => "Points held for: {$w->title_en}", 'by_user_id' => auth()->id(), 'at' => now()]);
            $e->log("طلب استبدال مكافأة: {$w->title_ar} (−{$w->cost} نقطة محجوزة)", "Reward requested: {$w->title_en} (−{$w->cost} points held)");
            Notify::hr("طلب استبدال مكافأة «{$w->title_ar}» من {$e->name}", "Reward request «{$w->title_en}» from {$e->name_en}", 'referrals');
            return $r;
        });
    }

    public function decide(Redemption $r, User $u, bool $approve, ?string $note): Redemption
    {
        $note = trim((string) $note) ?: null;
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (! $approve && mb_strlen((string) $note) < $min) throw ApiException::field('note', L::t("سبب الرفض إلزامي ({$min} أحرف على الأقل).", "A reason is required (at least {$min} characters)."), 'reason_required');
        return DB::transaction(function () use ($r, $u, $approve, $note) {
            $r = Redemption::with(['reward', 'employee'])->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') throw new ApiException(L::t('سبق القرار في هذا الطلب.', 'This request was already decided.'), 'decided', 409);
            if ($r->employee_id === $u->employee_id) throw new ApiException(L::t('لا تقرر في طلبك.', 'You cannot decide your own request.'), 'forbidden', 403);
            $e = Employee::whereKey($r->employee_id)->lockForUpdate()->firstOrFail();
            $w = Reward::whereKey($r->reward_id)->lockForUpdate()->firstOrFail();
            if ($approve) {
                if ($w->stock < 1) throw new ApiException(L::t('نفد مخزون هذه المكافأة — ارفض الطلب لتُعاد النقاط.', 'This reward is out of stock — decline to refund the points.'), 'out_of_stock', 409);
                $w->update(['stock' => $w->stock - 1]);
            } else {
                $e->update(['points' => $e->points + $r->cost]);
                PointLog::create(['employee_id' => $e->id, 'points' => $r->cost, 'reason_ar' => "إعادة نقاط — رُفض استبدال {$w->title_ar}", 'reason_en' => "Points refunded — {$w->title_en} declined", 'by_user_id' => $u->id, 'at' => now()]);
            }
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'note' => $note, 'decided_by' => $u->displayName('ar'), 'decided_by_user_id' => $u->id, 'decided_at' => now()]);
            $e->log($approve ? "اعتماد مكافأة: {$w->title_ar}" : "رفض مكافأة {$w->title_ar} وإعادة {$r->cost} نقطة: {$note}", $approve ? "Reward approved: {$w->title_en}" : "Reward {$w->title_en} declined, {$r->cost} points refunded", $approve ? 'ok' : 'warn');
            Notify::user($e->user()->value('id'), $approve ? "اعتُمدت مكافأتك «{$w->title_ar}»" : "لم تُعتمد «{$w->title_ar}» وأُعيدت {$r->cost} نقطة: {$note}",
                $approve ? "Your reward «{$w->title_en}» is approved" : "«{$w->title_en}» was declined and {$r->cost} points refunded", 'rewards');
            Audit::record('redemption.'.($approve ? 'approved' : 'rejected'), $r, null, ['reward' => $w->id, 'cost' => $r->cost, 'note' => $note]);
            return $r->refresh();
        });
    }

    public static function rewardRow(Reward $w): array
    {
        return ['id' => $w->id, 'title' => ['ar' => $w->title_ar, 'en' => $w->title_en], 'cost' => (int) $w->cost, 'stock' => (int) $w->stock, 'kind' => $w->kind, 'active' => (bool) $w->active];
    }

    public static function redemptionRow(Redemption $r): array
    {
        return ['id' => $r->id, 'employee' => $r->employee ? ['id' => $r->employee->id, 'code' => $r->employee->code, 'name' => ['ar' => $r->employee->name, 'en' => $r->employee->name_en]] : null,
            'reward' => $r->reward ? ['id' => $r->reward->id, 'title' => ['ar' => $r->reward->title_ar, 'en' => $r->reward->title_en], 'kind' => $r->reward->kind] : null,
            'cost' => (int) $r->cost, 'status' => $r->status, 'note' => $r->note, 'decided_by' => $r->decided_by, 'at' => $r->created_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String()];
    }

    public static function referralRow(Referral $r): array
    {
        $c = $r->candidate;
        return ['id' => $r->id, 'code' => $r->code, 'clicks' => (int) $r->clicks, 'status' => $r->status, 'awarded' => (int) $r->awarded, 'created_at' => $r->created_at?->toIso8601String(),
            'employee' => $r->employee ? ['id' => $r->employee->id, 'name' => ['ar' => $r->employee->name, 'en' => $r->employee->name_en]] : null,
            'job' => $r->jobOpening ? ['id' => $r->jobOpening->id, 'code' => $r->jobOpening->code, 'title' => ['ar' => $r->jobOpening->title_ar, 'en' => $r->jobOpening->title_en], 'hard_to_fill' => (bool) $r->jobOpening->hard_to_fill] : null,
            'candidate' => $c ? ['id' => $c->id, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'stage' => $c->stage] : null];
    }
}
