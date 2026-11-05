<?php

namespace App\Services\Work;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\PointLog;
use App\Models\User;
use App\Support\Notify;
use App\Support\Settings;

/**
 * النقاط من الأداء (ملاحظة 61): إنجاز المهام، تحقيق مؤشرات الأداء الفردية، ونتيجة التقييم المعتمد —
 * تُضاف إلى رصيد متجر المكافآت نفسه، مرة واحدة لكل مصدر (source + source_id)، وكل القيم من إعداد «points».
 */
class Points
{
    public static function config(): array
    {
        $d = Settings::DEFAULTS['points'];
        $s = Settings::get('points') ?? [];
        $out = array_merge($d, array_intersect_key($s, $d));
        foreach (['task', 'review'] as $k) $out[$k] = array_merge($d[$k], array_intersect_key($s[$k] ?? [], $d[$k]));
        return $out;
    }

    /** يضيف النقاط مرة واحدة فقط لكل مصدر — يعيد عدد النقاط المضافة (0 إن سبق أو كانت صفراً) */
    public static function award(Employee $e, int $points, string $source, int $sourceId, string $ar, string $en, ?int $by = null, bool $performance = true, bool $notify = true): int
    {
        // $performance = false: مصدر بإعداده الخاص (شكر زميل) لا يتبع مفتاح «النقاط من الأداء»
        if ($points <= 0 || ($performance && ! self::config()['enabled'])) return 0;
        if (PointLog::where('source', $source)->where('source_id', $sourceId)->where('employee_id', $e->id)->exists()) return 0;
        PointLog::create(['employee_id' => $e->id, 'points' => $points, 'reason_ar' => $ar, 'reason_en' => $en, 'by_user_id' => $by, 'at' => now(), 'source' => $source, 'source_id' => $sourceId]);
        // الرصيد في ملف الموظف (يُصرف منه في متجر المكافآت) + الرصيد التراكمي للوحة المتصدرين
        Employee::whereKey($e->id)->update(['points' => \DB::raw('points + '.$points), 'lifetime_points' => \DB::raw('lifetime_points + '.$points)]);
        if ($notify && $uid = User::where('employee_id', $e->id)->value('id')) Notify::user($uid, "أُضيفت {$points} نقطة إلى رصيدك: {$ar}", "{$points} points added: {$en}", 'rewards');
        return $points;
    }

    /** نقاط المهمة حسب أولويتها، ومضروبة بمعامل التأخر إن أُنجزت بعد موعدها */
    public static function forTask(string $priority, bool $late): int
    {
        $c = self::config();
        $p = (int) ($c['task'][$priority] ?? 0);
        return (int) round($late ? $p * (float) $c['late_factor'] : $p);
    }

    /** نقاط المؤشر حسب نسبة التحقيق */
    public static function forKpi(float $achievement): int
    {
        $c = self::config();
        return $achievement >= (float) $c['exceed_pct'] ? (int) $c['kpi_exceed'] : ($achievement >= 100 ? (int) $c['kpi_met'] : 0);
    }

    /** نتيجة التقييم المعتمد: المستوى العالي/المتوسط/المنخفض ← نقاط */
    public static function review(PerformanceReview $r): int
    {
        $level = [3 => 'high', 2 => 'mid', 1 => 'low'][(int) $r->performance] ?? null;
        if (! $level || ! $r->employee) return 0;
        $pts = (int) (self::config()['review'][$level] ?? 0);
        return self::award($r->employee, $pts, 'review', $r->id, 'نتيجة تقييم الأداء', 'Performance review result');
    }
}
