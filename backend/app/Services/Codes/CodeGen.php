<?php

namespace App\Services\Codes;

use App\Exceptions\ApiException;
use App\Support\Counters;
use App\Support\L;
use App\Support\Settings;

/**
 * مولّد الرموز المركّبة بقوالب قابلة للتهيئة (FR-CS-010/011): {CO}-{YY}-{SEQ4} للرقم الوظيفي، ومثلها للوثيقة والمعاملة والجهاز والموقع الوظيفي.
 * لكل قالب عدّاد مستقل لكل نطاق (السنة، الفرع، القسم…) آمن من التكرار عند الإدخال المتزامن (قفل الصف في Counters).
 */
class CodeGen
{
    /** القوالب الافتراضية: الرقم الوظيفي يبقى على صيغة الشركة الحالية UHR-1033 حتى تغيّره الشركة */
    public const TEMPLATES = [
        'employee_no' => ['template' => '{PREFIX}-{SEQ}', 'start' => 1001, 'ar' => 'الرقم الوظيفي', 'en' => 'Employee number'],
        'position' => ['template' => '{BR}-{DEPT}-{JOB}-{SEQ2}', 'start' => 1, 'ar' => 'الموقع الوظيفي', 'en' => 'Position'],
        'document' => ['template' => '{DEPT}-{POL}-{TYPE}-{SEQ2}', 'start' => 1, 'ar' => 'رمز الوثيقة', 'en' => 'Document code'],
        'transaction' => ['template' => '{TYPE}-{YY}-{BR}-{SEQ5}', 'start' => 1, 'ar' => 'رقم المعاملة', 'en' => 'Transaction number'],
        'device' => ['template' => 'DV-{BR}-{SEQ2}', 'start' => 1, 'ar' => 'رمز الجهاز', 'en' => 'Device code'],
    ];

    /** الرموز المسموحة في القوالب وما يُحسب منها نطاقاً للعداد */
    public const TOKENS = ['CO', 'PREFIX', 'YY', 'YYYY', 'BR', 'DEPT', 'JOB', 'POL', 'TYPE'];
    private const SCOPE = ['YY', 'YYYY', 'BR', 'DEPT', 'JOB', 'POL', 'TYPE'];

    public static function templates(): array
    {
        $saved = Codes::config()['templates'] ?? [];
        $out = [];
        foreach (self::TEMPLATES as $k => $d) $out[$k] = ['template' => $saved[$k]['template'] ?? $d['template'], 'start' => (int) ($saved[$k]['start'] ?? $d['start']), 'ar' => $d['ar'], 'en' => $d['en'], 'default' => $d['template']];
        return $out;
    }

    /** يتحقق من قالب: رموز معروفة، تسلسل واحد، وأحرف مسموحة فقط */
    public static function check(string $tpl): ?string
    {
        if (! preg_match('/^[A-Z0-9{}\-_\/.]{3,60}$/', $tpl)) return L::t('القالب بأحرف لاتينية كبيرة وأرقام و - و {} فقط.', 'Capital Latin letters, digits, - and {} only.');
        preg_match_all('/\{([A-Z]+)(\d?)\}/', $tpl, $m);
        $seq = 0;
        foreach ($m[1] as $i => $t) {
            if ($t === 'SEQ') { $seq++; continue; }
            if (! in_array($t, self::TOKENS, true) || $m[2][$i] !== '') return str_replace(':t', '{'.$t.$m[2][$i].'}', L::t('رمز غير معروف في القالب: :t', 'Unknown token: :t'));
        }
        if ($seq !== 1) return L::t('يجب أن يحتوي القالب على {SEQ} واحد (مثل {SEQ4} لأربع خانات).', 'The template needs exactly one {SEQ} (e.g. {SEQ4} for four digits).');
        $rest = preg_replace('/\{[A-Z]+\d?\}/', '', $tpl);
        if (str_contains($rest, '{') || str_contains($rest, '}')) return L::t('أقواس غير مغلقة في القالب.', 'Unbalanced braces in the template.');
        return null;
    }

    /** يولّد الرمز التالي (يحجز رقماً) — $ctx: br, dept, job, pol, type */
    public static function next(string $name, array $ctx = []): string { return self::build($name, $ctx, true); }

    /** معاينة دون حجز رقم */
    public static function preview(string $name, array $ctx = [], ?string $tpl = null, ?int $start = null): string { return self::build($name, $ctx, false, $tpl, $start); }

    private static function build(string $name, array $ctx, bool $reserve, ?string $tpl = null, ?int $start = null): string
    {
        $t = self::templates()[$name] ?? throw new ApiException(L::t('قالب غير معروف.', 'Unknown template.'), 'not_found', 404);
        $tpl ??= $t['template']; $start ??= $t['start'];
        $vals = ['CO' => strtoupper((string) (Codes::config()['company_code'] ?: Settings::prefix('employee'))), 'PREFIX' => Settings::prefix('employee'),
            'YY' => now()->format('y'), 'YYYY' => now()->format('Y'), 'BR' => strtoupper((string) ($ctx['br'] ?? 'BR01')), 'DEPT' => strtoupper((string) ($ctx['dept'] ?? 'HR')),
            'JOB' => strtoupper((string) ($ctx['job'] ?? '014')), 'POL' => str_pad((string) ($ctx['pol'] ?? '01'), 2, '0', STR_PAD_LEFT), 'TYPE' => strtoupper((string) ($ctx['type'] ?? 'F'))];
        preg_match_all('/\{([A-Z]+)(\d?)\}/', $tpl, $m);
        $scope = []; foreach ($m[1] as $tok) if (in_array($tok, self::SCOPE, true)) $scope[] = $vals[$tok];
        // الرقم الوظيفي بالقالب الافتراضي يتابع العداد الحالي «employee» دون انقطاع
        $key = $name === 'employee_no' ? 'employee' : "tpl-{$name}";
        if ($scope) $key .= ':'.implode('-', $scope);
        $n = $reserve ? Counters::next($key, $start) : self::peek($key, $start);
        return preg_replace_callback('/\{([A-Z]+)(\d?)\}/', fn ($x) => $x[1] === 'SEQ' ? str_pad((string) $n, (int) ($x[2] ?: 1), '0', STR_PAD_LEFT) : $vals[$x[1]], $tpl);
    }

    private static function peek(string $key, int $start): int
    {
        $k = ($c = \App\Support\Tenant::id()) ? "c{$c}:{$key}" : $key;
        $v = \Illuminate\Support\Facades\DB::table('counters')->where('key', $k)->value('value');
        return $v === null ? $start : $v + 1;
    }
}
