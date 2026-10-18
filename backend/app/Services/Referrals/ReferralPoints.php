<?php

namespace App\Services\Referrals;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\PointLog;
use App\Models\Referral;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * نقاط الإحالة المرحلية (SRS §10.9 — تحل محل 500 عند التعيين):
 * 1 تجاوز الفرز الأولي · 2 التأهل للمقابلة · 3 توقيع العرض ومباشرة العمل · 4 اجتياز فترة التجربة.
 * القيم من قواعد الشركة (10/20/30/40)، ×2 للوظائف الحرجة؛ مرة واحدة لكل مرحلة، ولا نقاط بعد رفض المرشح.
 */
class ReferralPoints
{
    public const STAGES = [
        1 => ['تجاوز الفرز الأولي', 'passed initial screening'],
        2 => ['التأهل للمقابلة', 'qualified for interview'],
        3 => ['توقيع العرض ومباشرة العمل', 'signed the offer and started work'],
        4 => ['اجتياز فترة التجربة', 'passed probation'],
    ];

    /** أعلى مرحلة إحالة تحققت بمرحلة المتقدم في مسار التوظيف (4 تأتي من تثبيت التعيين فقط) */
    public static function reached(string $stage): int
    {
        return match ($stage) { 'screening' => 1, 'interview', 'technical', 'offer' => 2, 'hired' => 3, default => 0 };
    }

    /** النقاط لمرحلة على هذا الشاغر (قبل منحها) */
    public static function value(int $stage, bool $hardToFill): int
    {
        $base = (int) (Settings::rule('referral_stages')[$stage - 1] ?? 0);
        return $hardToFill ? $base * max(1, (int) Settings::rule('hard_to_fill_x')) : $base;
    }

    /** بعد كل تغيّر في مرحلة المتقدم: يمنح المراحل المستحقة الناقصة، أو يغلق الإحالة عند الرفض */
    public function sync(Candidate $c): void
    {
        if (! $c->referrer_id) return;
        if ($c->stage === 'rejected') {
            Referral::where('candidate_id', $c->id)->whereNotIn('status', ['rewarded'])->update(['status' => 'closed']);
            return;
        }
        for ($s = 1; $s <= self::reached($c->stage); $s++) $this->award($c, $s);
        if ($c->stage === 'hired') Referral::where('candidate_id', $c->id)->whereIn('status', ['shared', 'applied', 'processing'])->update(['status' => 'hired']);
    }

    /** منح مرحلة واحدة — يتجاهل المكرر والمرفوض والمُحيل المنتهية خدمته */
    public function award(Candidate $c, int $stage): ?PointLog
    {
        if (! $c->referrer_id || $c->stage === 'rejected' || ! isset(self::STAGES[$stage])) return null;
        return DB::transaction(function () use ($c, $stage) {
            $emp = Employee::whereKey($c->referrer_id)->lockForUpdate()->first();
            if (! $emp || $emp->status === 'terminated') return null;
            if (PointLog::where('candidate_id', $c->id)->where('stage', $stage)->exists()) return null;
            $job = $c->jobOpening()->first();
            $pts = self::value($stage, (bool) $job?->hard_to_fill);
            if ($pts <= 0) return null;
            [$sa, $se] = self::STAGES[$stage];
            $x = $job?->hard_to_fill ? ' (×'.max(1, (int) Settings::rule('hard_to_fill_x')).' وظيفة حرجة)' : '';
            $xe = $job?->hard_to_fill ? ' (×'.max(1, (int) Settings::rule('hard_to_fill_x')).' hard-to-fill)' : '';
            $nameEn = $c->name_en ?: $c->name;
            $ref = Referral::where('candidate_id', $c->id)->first();
            $log = PointLog::create(['employee_id' => $emp->id, 'referral_id' => $ref?->id, 'candidate_id' => $c->id, 'stage' => $stage, 'points' => $pts,
                'reason_ar' => "إحالة {$c->name} — المرحلة {$stage}: {$sa}{$x}", 'reason_en' => "Referral {$nameEn} — stage {$stage}: {$se}{$xe}", 'by_user_id' => auth()->id(), 'at' => now()]);
            $emp->update(['points' => $emp->points + $pts, 'lifetime_points' => $emp->lifetime_points + $pts]);
            $emp->log("+{$pts} نقطة: {$c->name} — {$sa}", "+{$pts} points: {$nameEn} {$se}", 'ok');
            $ref?->update(['awarded' => (int) $ref->awarded + $pts, 'status' => match ($stage) { 1, 2 => 'processing', 3 => 'hired', 4 => 'rewarded' }]);
            Notify::user($emp->user()->value('id'), "أُضيفت {$pts} نقطة لرصيدك — {$c->name}: {$sa}", "{$pts} points added — {$nameEn} {$se}", 'referrals');
            return $log;
        });
    }
}
