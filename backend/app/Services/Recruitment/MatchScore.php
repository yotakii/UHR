<?php

namespace App\Services\Recruitment;

use App\Models\Candidate;
use App\Models\JobOpening;
use App\Support\Settings;

/**
 * مطابقة المتقدم مع الوظيفة (0–100) — منطق matchScore في rules.ts بالديمو، بمعايرة تضبطها كل شركة (Settings::match):
 * أوزان المعايير الخمسة (والشاغر يمكنه تجاوزها بأوزانه)، ودرجة كل حالة داخل كل معيار. القيم الافتراضية = قيم الديمو بالضبط.
 */
class MatchScore
{
    public const KEYS = ['exp', 'deg', 'sal', 'eng', 'file'];

    public const DEGREES = [
        ['ar' => 'ثانوية', 'en' => 'Secondary'], ['ar' => 'معهد متوسط', 'en' => 'Diploma'],
        ['ar' => 'إجازة جامعية', 'en' => "Bachelor's"], ['ar' => 'ماجستير', 'en' => "Master's"], ['ar' => 'دكتوراه', 'en' => 'PhD'],
    ];

    public const ENGLISH = [
        'excellent' => ['ar' => 'ممتاز', 'en' => 'Excellent'], 'good' => ['ar' => 'جيد', 'en' => 'Good'],
        'fair' => ['ar' => 'مقبول', 'en' => 'Fair'], 'weak' => ['ar' => 'ضعيف', 'en' => 'Weak'],
    ];

    /** المعايرة الفعلية لشاغر: معايرة الشركة + أوزان الشاغر إن حُددت */
    public static function config(?JobOpening $job = null, ?array $company = null): array
    {
        $cfg = $company ?? Settings::match();
        if ($job && is_array($job->match_weights) && array_sum($job->match_weights) === 100) $cfg['weights'] = array_intersect_key($job->match_weights + $cfg['weights'], $cfg['weights']);
        return $cfg;
    }

    /** @return array{total: int, parts: list<array{key: string, ar: string, en: string, weight: int, score: float, note_ar: string, note_en: string}>} */
    public static function compute(Candidate $c, JobOpening $job, ?array $cfg = null): array
    {
        $cfg ??= self::config($job);
        $w = $cfg['weights']; $pc = fn ($v) => (float) $v / 100;
        $years = (int) $c->years_exp; $minYears = (int) $job->min_years;
        $level = (int) $c->degree_level; $degree = (int) $job->degree;
        $salary = (float) $c->salary; $max = (float) $job->salary_max;

        $e = $cfg['exp'];
        $exp = $years >= $minYears
            ? $pc($e['meets']) + $pc(100 - (float) $e['meets']) * ((int) $e['bonus_years'] > 0 ? min(1, ($years - $minYears) / (int) $e['bonus_years']) : 1)
            : ($years / max(1, $minYears)) * $pc($e['below_max']);
        $d = $cfg['deg'];
        $deg = $pc($level > $degree ? $d['above'] : ($level === $degree ? $d['equal'] : ($level === $degree - 1 ? $d['one_below'] : $d['lower'])));
        $s = $cfg['sal'];
        $mid = ((float) $job->salary_min + $max) / 2;
        $sal = $salary <= $mid ? $pc($s['to_mid']) : ($salary <= $max ? $pc($s['to_max']) : max(0, $pc($s['over']) - ($salary - $max) / max(1, $max) * (float) $s['over_penalty']));
        $eng = isset($cfg['eng'][$c->english]) ? $pc($cfg['eng'][$c->english]) : 0.5;
        $items = array_filter($cfg['file']);
        $have = ['cv' => $c->cv_file_id, 'education' => count($c->education ?? []), 'experience' => count($c->experience ?? []), 'refs' => count($c->refs ?? []), 'linkedin' => $c->linkedin];
        $filled = $items ? count(array_filter(array_intersect_key($have, $items))) / count($items) : 0;

        $within = $salary <= $max;
        $parts = array_values(array_filter([
            ['key' => 'exp', 'ar' => 'سنوات الخبرة', 'en' => 'Experience', 'weight' => (int) $w['exp'], 'score' => $exp, 'note_ar' => "{$years} من {$minYears} مطلوبة", 'note_en' => "{$years} of {$minYears} required"],
            ['key' => 'deg', 'ar' => 'المؤهل العلمي', 'en' => 'Qualification', 'weight' => (int) $w['deg'], 'score' => $deg, 'note_ar' => self::DEGREES[$level]['ar'] ?? '—', 'note_en' => self::DEGREES[$level]['en'] ?? '—'],
            ['key' => 'sal', 'ar' => 'الراتب المتوقع', 'en' => 'Salary expectation', 'weight' => (int) $w['sal'], 'score' => $sal, 'note_ar' => $within ? 'ضمن النطاق' : 'أعلى من النطاق', 'note_en' => $within ? 'Within band' : 'Above band'],
            ['key' => 'eng', 'ar' => 'اللغة الإنكليزية', 'en' => 'English', 'weight' => (int) $w['eng'], 'score' => $eng, 'note_ar' => self::ENGLISH[$c->english]['ar'] ?? $c->english, 'note_en' => self::ENGLISH[$c->english]['en'] ?? $c->english],
            ['key' => 'file', 'ar' => 'اكتمال الملف', 'en' => 'File completeness', 'weight' => (int) $w['file'], 'score' => $filled, 'note_ar' => round($filled * 100).'٪', 'note_en' => round($filled * 100).'%'],
        ], fn ($p) => $p['weight'] > 0));
        $total = array_sum(array_map(fn ($p) => $p['score'] * $p['weight'], $parts));
        return ['total' => max(0, min(100, self::jsRound($total))), 'parts' => $parts];
    }

    /**
     * بعد تغيير المعايرة: إعادة حساب نسبة المطابقة ونطاقها للطلبات المفتوحة (الشركة كلها أو شاغر واحد).
     * لا تُنقل الطلبات بين المراحل ولا يُرفض أحد بأثر رجعي — تتغير الأرقام المعروضة فقط.
     */
    public static function recompute(?JobOpening $only = null): int
    {
        $n = 0;
        Candidate::with('jobOpening')->whereNotIn('stage', ['hired', 'rejected'])->where('cv_status', '!=', 'erased')
            ->when($only, fn ($q) => $q->where('job_opening_id', $only->id))
            ->each(function (Candidate $c) use (&$n) {
                $m = self::total($c, $c->jobOpening);
                if ($m === (int) $c->match_score) return;
                $c->update(['match_score' => $m, 'match_band' => MatchBands::of($m)]);
                $n++;
            });
        return $n;
    }

    public static function total(Candidate $c, JobOpening $job, ?array $cfg = null): int { return self::compute($c, $job, $cfg)['total']; }

    /** Math.round في JS (النصف للأعلى دائماً) — لتطابق الديمو رقمياً */
    private static function jsRound(float $x): int { return (int) floor($x + 0.5); }
}
