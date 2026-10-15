<?php

namespace App\Services\Recruitment;

/**
 * تقييم المقابلة الأولية F-25 — نقل F25 وf25Score وf25Label من rules.ts:
 * أربع فئات × ستة معايير (1–5)، كل فئة من 7.5، المجموع من 30، والنسبة المئوية.
 */
class F25
{
    public const GROUPS = [
        ['key' => 'c1', 'ar' => 'المواصفات الوظيفية', 'en' => 'Job specifications', 'items' => [
            ['readiness', 'الجاهزية العامة للوظيفة', 'General readiness'], ['physical', 'القدرة والجاهزية الجسدية', 'Physical readiness'],
            ['education', 'المؤهل العلمي والتخصص', 'Education & major'], ['experience', 'سنوات الخبرة ونوعيتها', 'Experience depth'],
            ['knowledge', 'المعرفة الفنية بالتخصص', 'Technical knowledge'], ['english', 'إتقان اللغة الإنكليزية', 'English']]],
        ['key' => 'c2', 'ar' => 'المهارات المباشرة', 'en' => 'Direct skills', 'items' => [
            ['comm', 'مهارات التواصل', 'Communication'], ['negotiation', 'الإقناع والتفاوض', 'Negotiation'],
            ['computer', 'إتقان الحاسب والبرمجيات', 'Computer skills'], ['flex', 'المرونة والتكيف مع التغيير', 'Adaptability'],
            ['learning', 'استمرارية التعلم', 'Continuous learning'], ['overtime', 'الاستعداد للعمل الإضافي', 'Overtime readiness']]],
        ['key' => 'c3', 'ar' => 'الشخصية والسلوك', 'en' => 'Personality & behaviour', 'items' => [
            ['professional', 'المظهر واللباقة المهنية', 'Professional presence'], ['behavior', 'السلوك وحسن التصرف', 'Conduct'],
            ['body', 'لغة الجسد والحضور', 'Body language'], ['confidence', 'الثقة بالنفس والاتزان', 'Self-confidence'],
            ['maturity', 'النضج المهني', 'Professional maturity'], ['responsibility', 'تحمل المسؤولية', 'Accountability']]],
        ['key' => 'c4', 'ar' => 'المهارات القيادية (إن وجدت)', 'en' => 'Leadership (if applicable)', 'items' => [
            ['problem', 'حل المشكلات والتحليل', 'Problem solving'], ['goals', 'تحديد الأهداف وتحقيقها', 'Goal setting'],
            ['risk', 'إدارة المخاطر', 'Risk management'], ['planning', 'التخطيط', 'Planning'],
            ['influence', 'التأثير والإقناع', 'Influence'], ['team', 'قيادة وتحفيز الفريق', 'Team leadership']]],
    ];

    /** مفاتيح المعايير الـ24 */
    public static function keys(): array
    {
        return array_merge(...array_map(fn ($g) => array_column($g['items'], 0), self::GROUPS));
    }

    /** @return array{cats: float[], total: float, pct: int} */
    public static function score(array $ratings): array
    {
        $cats = array_map(fn ($g) => array_sum(array_map(fn ($i) => (int) ($ratings[$i[0]] ?? 0), $g['items'])) / 30 * 7.5, self::GROUPS);
        $total = array_sum($cats);
        return ['cats' => $cats, 'total' => $total, 'pct' => (int) round($total / 30 * 100)];
    }

    public static function label(int $pct): array
    {
        return match (true) {
            $pct >= 85 => ['ar' => 'ممتاز — موصى بالتعيين', 'en' => 'Excellent — recommend hire'],
            $pct >= 70 => ['ar' => 'جيد جداً — مقبول', 'en' => 'Very good — acceptable'],
            $pct >= 50 => ['ar' => 'متوسط — يحتاج مراجعة', 'en' => 'Average — needs review'],
            default => ['ar' => 'غير كافٍ', 'en' => 'Insufficient'],
        };
    }
}
