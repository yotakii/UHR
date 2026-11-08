<?php

namespace App\Services\Assistant;

use App\Models\PolicyParagraph;
use App\Models\User;
use App\Support\Perm;

/**
 * معرفة «المساعد»: فقرات سياسات الشركة المنشورة (مكتبة السياسات — لكل شركة) + مقاطع دليل المستخدم (resources/assistant/guide.json).
 * ترتيب BM25 مبسّط على جذور الكلمات مع مطابقة البادئة (صرف العربية)، وعتبة تغطية تمنع إرجاع مقاطع لا صلة لها.
 */
class Knowledge
{
    private static ?array $guide = null;

    /** @return array<int, array{kind: string, title: array, sub: ?string, text: string, to: ?string, score: float}> */
    public function search(User $u, string $question, int $limit = 3): array
    {
        $q = Text::terms(self::translate($question));
        if (! $q) return [];
        $docs = array_merge($this->policies($u, $q), $this->guide($u));
        if (! $docs) return [];
        $n = count($docs); $avg = array_sum(array_map(fn ($d) => $d['len'], $docs)) / $n;
        $df = [];
        foreach ($q as $t) foreach ($docs as $d) if (self::tf($d, $t) > 0 || (($alt = self::alt($t)) && self::tf($d, $alt) > 0)) $df[$t] = ($df[$t] ?? 0) + 1;
        $idf = []; foreach ($q as $t) $idf[$t] = log(1 + ($n - ($df[$t] ?? 0) + 0.5) / (($df[$t] ?? 0) + 0.5));
        $total = array_sum($idf) ?: 1;
        $out = [];
        foreach ($docs as $d) {
            $s = 0.0; $cover = 0.0;
            foreach ($q as $t) {
                $tf = self::tf($d, $t) + 0.6 * self::tf(['tokens' => $d['head']], $t);
                if ($tf <= 0 && ($alt = self::alt($t))) $tf = 0.8 * (self::tf($d, $alt) + 0.6 * self::tf(['tokens' => $d['head']], $alt));
                if ($tf <= 0) continue;
                $s += $idf[$t] * ($tf * 2.2) / ($tf + 1.2 * (0.25 + 0.75 * $d['len'] / $avg));
                $cover += $idf[$t];
            }
            if ($s <= 0 || $cover / $total < 0.45) continue;
            $out[] = ['kind' => $d['kind'], 'title' => $d['title'], 'sub' => $d['sub'], 'text' => $d['text'], 'to' => $d['to'], 'score' => round($s * ($d['kind'] === 'policy' ? 1.25 : 1), 3)];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        // مقطع واحد لكل عنوان فرعي — تنوع أكثر في المصادر
        $seen = []; $res = [];
        foreach ($out as $o) { $k = $o['kind'].'|'.$o['title']['ar'].'|'.$o['sub']; if (isset($seen[$k])) continue; $seen[$k] = true; $res[] = $o; if (count($res) >= $limit) break; }
        return $res;
    }

    /** وثائق الشركة عربية: كلمات السؤال الإنكليزي الشائعة تُضاف بمقابلها العربي */
    private const EN = ['sick' => 'مرضيه', 'leave' => 'اجازه', 'annual' => 'سنويه', 'vacation' => 'اجازه', 'late' => 'تاخير', 'lateness' => 'تاخير', 'absence' => 'غياب', 'absent' => 'غياب',
        'resign' => 'استقاله', 'resignation' => 'استقاله', 'notice' => 'انذار', 'salary' => 'راتب', 'advance' => 'سلفه', 'overtime' => 'اضافي', 'holiday' => 'عطله', 'attendance' => 'حضور',
        'policy' => 'سياسه', 'certificate' => 'شهاده', 'penalty' => 'عقوبه', 'warning' => 'انذار', 'probation' => 'تجربه', 'maternity' => 'امومه', 'marriage' => 'زواج', 'death' => 'وفاه',
        'hourly' => 'ساعيه', 'emergency' => 'طارئه', 'unpaid' => 'مدفوعه', 'insurance' => 'تامينات', 'shift' => 'ورديه', 'hours' => 'ساعات', 'termination' => 'انهاء', 'custody' => 'عهده',
        'violation' => 'مخالفه', 'complaint' => 'شكوي', 'training' => 'تدريب', 'promotion' => 'ترقيه', 'transfer' => 'نقل', 'substitute' => 'بديل', 'justify' => 'تبرير', 'justification' => 'تبرير', 'period' => 'مده', 'deduction' => 'حسم', 'paid' => 'مدفوعه', 'days' => 'ايام', 'leaves' => 'اجازات'];

    public static function translate(string $q): string
    {
        if (! preg_match('/[a-z]{3,}/i', $q)) return $q;
        $add = [];
        $out = [];
        foreach (preg_split('/\s+/u', mb_strtolower($q)) as $w) {
            $k = preg_replace('/[^a-z]/', '', $w);
            $out[] = self::EN[$k] ?? self::EN[rtrim($k, 's')] ?? $w;   // الكلمة المترجمة تحل محل الإنكليزية
        }
        return implode(' ', $out);
    }

    /** فعل مضارع (أسجّل، أطلب، نقدّم…) ← جذره بلا حرف المضارعة، ليطابق المصدر (تسجيل، طلب، تقديم) */
    private static function alt(string $t): ?string
    {
        return mb_strlen($t) >= 4 && in_array(mb_substr($t, 0, 1), ['ا', 'ي', 'ت', 'ن'], true) ? mb_substr($t, 1) : null;
    }

    /** عدد مرات الكلمة، أو كلمة تبدأ بها، أو تحتويها (للجذر من 3 أحرف فأكثر) بوزن أقل */
    private static function tf(array $d, string $t): float
    {
        $n = $d['tokens'][$t] ?? 0;
        if (mb_strlen($t) >= 3) foreach ($d['tokens'] as $w => $c) {
            if ($w === $t) continue;
            if (str_starts_with($w, $t)) $n += 0.7 * $c;
            elseif (mb_strlen($t) >= 3 && mb_strlen($w) <= mb_strlen($t) + 3 && str_contains($w, $t)) $n += 0.5 * $c;
        }
        return (float) $n;
    }

    private static function bag(string $text): array
    {
        $b = [];
        foreach (explode(' ', Text::norm($text)) as $w) {
            if (mb_strlen($w) < 2 || in_array($w, Text::STOP, true)) continue;
            $s = Text::stem($w); $b[$s] = ($b[$s] ?? 0) + 1;
        }
        return $b;
    }

    private function policies(User $u, array $terms): array
    {
        $pfx = array_values(array_filter(array_map(fn ($t) => mb_substr($t, 0, 4), $terms), fn ($t) => mb_strlen($t) >= 3));
        if (! $pfx) return [];
        $rows = PolicyParagraph::query()->join('policy_versions as v', 'v.id', '=', 'policy_paragraphs.version_id')->join('policy_documents as d', 'd.id', '=', 'v.document_id')
            ->where('v.status', 'published')->where('d.status', 'published')
            ->where(function ($w) use ($pfx) { foreach ($pfx as $x) $w->orWhere('policy_paragraphs.norm', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $x).'%'); })
            ->limit(400)->get(['policy_paragraphs.id', 'policy_paragraphs.n', 'policy_paragraphs.text', 'd.id as document_id', 'd.title_ar', 'd.title_en']);
        $base = Assistant::link($u, '/me/policies');
        return $rows->map(fn ($r) => ['kind' => 'policy', 'title' => ['ar' => $r->title_ar, 'en' => $r->title_en ?: $r->title_ar], 'sub' => null, 'text' => $r->text,
            'to' => $base.'?doc='.$r->document_id.'&p='.$r->n, 'tokens' => self::bag($r->text), 'head' => self::bag($r->title_ar), 'len' => max(1, count(explode(' ', $r->text)))])->all();
    }

    private function guide(User $u): array
    {
        self::$guide ??= array_map(function ($c) {
            $c['tokens'] = self::bag($c['text']); $c['head'] = self::bag($c['title'].' '.$c['sub']); $c['len'] = max(1, count(explode(' ', $c['text'])));
            return $c;
        }, json_decode((string) @file_get_contents(resource_path('assistant/guide.json')), true) ?: []);
        $hr = Perm::can($u, 'employees', 'view') || Perm::can($u, 'candidates', 'view') || Perm::can($u, 'settings', 'view');
        $out = [];
        foreach (self::$guide as $c) {
            if (! $hr && ! $c['employee']) continue;   // الموظف لا يرى شروح صفحات الموارد البشرية
            $to = $hr ? ($c['hr'] ?? $c['me']) : ($c['me'] ?? null);
            $out[] = ['kind' => 'guide', 'title' => ['ar' => $c['title'], 'en' => $c['title']], 'sub' => $c['sub'] !== $c['title'] ? $c['sub'] : null, 'text' => $c['text'], 'to' => $to,
                'tokens' => $c['tokens'], 'head' => $c['head'], 'len' => $c['len']];
        }
        return $out;
    }
}
