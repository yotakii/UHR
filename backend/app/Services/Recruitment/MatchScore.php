<?php

namespace App\Services\Recruitment;

use App\Models\Candidate;
use App\Models\JobOpening;

/**
 * مطابقة المتقدم مع الوظيفة (0–100) — منقولة حرفياً من matchScore في rules.ts بالديمو.
 * خمسة عناصر بأوزان: الخبرة 30، المؤهل 25، الراتب 20، الإنكليزية 15، اكتمال الملف 10.
 */
class MatchScore
{
    public const DEGREES = [
        ['ar' => 'ثانوية', 'en' => 'Secondary'], ['ar' => 'معهد متوسط', 'en' => 'Diploma'],
        ['ar' => 'إجازة جامعية', 'en' => "Bachelor's"], ['ar' => 'ماجستير', 'en' => "Master's"], ['ar' => 'دكتوراه', 'en' => 'PhD'],
    ];

    public const ENGLISH = [
        'excellent' => ['ar' => 'ممتاز', 'en' => 'Excellent', 'score' => 1.0], 'good' => ['ar' => 'جيد', 'en' => 'Good', 'score' => 0.7],
        'fair' => ['ar' => 'مقبول', 'en' => 'Fair', 'score' => 0.4], 'weak' => ['ar' => 'ضعيف', 'en' => 'Weak', 'score' => 0.1],
    ];

    /** @return array{total: int, parts: list<array{key: string, ar: string, en: string, weight: int, score: float, note_ar: string, note_en: string}>} */
    public static function compute(Candidate $c, JobOpening $job): array
    {
        $years = (int) $c->years_exp; $minYears = (int) $job->min_years;
        $level = (int) $c->degree_level; $degree = (int) $job->degree;
        $salary = (float) $c->salary; $max = (float) $job->salary_max;

        $exp = $years >= $minYears
            ? 0.7 + 0.3 * min(1, ($years - $minYears) / 3)
            : ($years / max(1, $minYears)) * 0.6;
        $deg = $level > $degree ? 1 : ($level === $degree ? 0.85 : ($level === $degree - 1 ? 0.4 : 0.1));
        $mid = ((float) $job->salary_min + $max) / 2;
        $sal = $salary <= $mid ? 1 : ($salary <= $max ? 0.75 : max(0, 0.5 - ($salary - $max) / $max * 2));
        $eng = self::ENGLISH[$c->english]['score'] ?? 0.5;
        $filled = count(array_filter([$c->cv_file_id, count($c->education ?? []), count($c->experience ?? []), count($c->refs ?? []), $c->linkedin])) / 5;

        $within = $salary <= $max;
        $parts = [
            ['key' => 'exp', 'ar' => 'سنوات الخبرة', 'en' => 'Experience', 'weight' => 30, 'score' => $exp, 'note_ar' => "{$years} من {$minYears} مطلوبة", 'note_en' => "{$years} of {$minYears} required"],
            ['key' => 'deg', 'ar' => 'المؤهل العلمي', 'en' => 'Qualification', 'weight' => 25, 'score' => $deg, 'note_ar' => self::DEGREES[$level]['ar'] ?? '—', 'note_en' => self::DEGREES[$level]['en'] ?? '—'],
            ['key' => 'sal', 'ar' => 'الراتب المتوقع', 'en' => 'Salary expectation', 'weight' => 20, 'score' => $sal, 'note_ar' => $within ? 'ضمن النطاق' : 'أعلى من النطاق', 'note_en' => $within ? 'Within band' : 'Above band'],
            ['key' => 'eng', 'ar' => 'اللغة الإنكليزية', 'en' => 'English', 'weight' => 15, 'score' => $eng, 'note_ar' => self::ENGLISH[$c->english]['ar'] ?? $c->english, 'note_en' => self::ENGLISH[$c->english]['en'] ?? $c->english],
            ['key' => 'file', 'ar' => 'اكتمال الملف', 'en' => 'File completeness', 'weight' => 10, 'score' => $filled, 'note_ar' => round($filled * 100).'٪', 'note_en' => round($filled * 100).'%'],
        ];
        $total = array_sum(array_map(fn ($p) => $p['score'] * $p['weight'], $parts));
        return ['total' => self::jsRound($total), 'parts' => $parts];
    }

    public static function total(Candidate $c, JobOpening $job): int { return self::compute($c, $job)['total']; }

    /** Math.round في JS (النصف للأعلى دائماً) — لتطابق الديمو رقمياً */
    private static function jsRound(float $x): int { return (int) floor($x + 0.5); }
}
