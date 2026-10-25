<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Services\Recruitment\MatchScore;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * قواعد التوظيف لكل شركة (صفحة «قواعد التوظيف والمطابقة»): نطاقات المطابقة والأتمتة، مهل المراحل، نقاط الإحالة،
 * مدة الاحتفاظ بالسير، عتبة التجربة في F-19 — ومعايرة المطابقة كاملة (الأوزان ودرجة كل حالة) مع معاينة قبل الحفظ.
 */
class RulesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['rules' => self::rules(), 'stage_sla' => Settings::get('stage_sla'), 'match' => Settings::match(),
            'defaults' => ['rules' => Settings::DEFAULTS['rules'], 'stage_sla' => Settings::DEFAULTS['stage_sla'], 'match' => Settings::DEFAULTS['match']]]);
    }

    /** قواعد الشركة مكمّلة بالقيم الافتراضية للمفاتيح الجديدة (ويُسقط ما أُلغي مثل referral_points) */
    private static function rules(): array
    {
        return array_intersect_key((Settings::get('rules') ?? []) + Settings::DEFAULTS['rules'], Settings::DEFAULTS['rules']);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'rules.match_threshold' => ['required', 'integer', 'between:1,100'], 'rules.review_min' => ['required', 'integer', 'between:0,99'], 'rules.reject_max' => ['required', 'integer', 'between:0,99'],
            'rules.auto_reject' => ['required', 'boolean'], 'rules.auto_screen' => ['required', 'boolean'], 'rules.sla_alerts' => ['required', 'boolean'], 'rules.whatsapp_nudge' => ['required', 'boolean'],
            'rules.referral_stages' => ['required', 'array', 'size:4'], 'rules.referral_stages.*' => ['required', 'integer', 'between:0,10000'],
            'rules.hard_to_fill_x' => ['required', 'integer', 'between:1,5'],
            'rules.cv_retention_months' => ['sometimes', 'integer', 'between:1,120'], 'rules.probation_at' => ['sometimes', 'integer', 'between:1,28'],
            'rules.internal_min_months' => ['sometimes', 'integer', 'between:0,120'], 'rules.internal_no_probation' => ['sometimes', 'boolean'], 'rules.internal_clean_months' => ['sometimes', 'integer', 'between:0,60'],
            'stage_sla' => ['required', 'array'], 'stage_sla.*' => ['integer', 'between:0,60'],
            'match' => ['sometimes', 'array'],
        ]);
        $r = $d['rules'];
        if (! ($r['reject_max'] < $r['review_min'] && $r['review_min'] < $r['match_threshold'])) {
            return response()->json(['message' => L::t('النطاقات يجب أن تتدرج: حد الرفض < بداية المراجعة < حد الفرز.', 'Bands must ascend: reject max < review min < screening threshold.'), 'code' => 'bands_order',
                'errors' => ['rules.review_min' => [L::t('رتّب النطاقات تصاعدياً', 'Order the bands ascending')]]], 422);
        }
        $match = isset($d['match']) ? self::validMatch($request->input('match')) : null;
        $before = ['rules' => self::rules(), 'stage_sla' => Settings::get('stage_sla'), 'match' => Settings::match()];
        $r['referral_stages'] = array_map('intval', array_values($r['referral_stages']));
        Settings::put('rules', array_intersect_key($r + self::rules(), Settings::DEFAULTS['rules']));
        Settings::put('stage_sla', array_intersect_key($d['stage_sla'], Settings::DEFAULTS['stage_sla']) + Settings::get('stage_sla'));
        if ($match) Settings::put('match', $match);
        // النسب المعروضة للطلبات المفتوحة تتبع المعايرة والنطاقات الجديدة
        $changed = MatchScore::recompute();
        Audit::record('settings.recruitment_rules', null, $before, ['rules' => $r, 'stage_sla' => $d['stage_sla'], 'match' => $match, 'recomputed' => $changed]);
        return response()->json(['recomputed' => $changed] + $this->show()->getData(true));
    }

    /** معاينة: نسبة المطابقة الحالية والجديدة لآخر الطلبات المفتوحة، قبل الحفظ */
    public function preview(Request $request): JsonResponse
    {
        $cfg = self::validMatch($request->input('match'));
        $rows = Candidate::with('jobOpening')->whereNotIn('stage', ['hired', 'rejected'])->where('cv_status', '!=', 'erased')->latest('created_at')->limit(12)->get()
            ->map(fn (Candidate $c) => ['id' => $c->id, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'job' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en],
                'current' => MatchScore::total($c, $c->jobOpening), 'new' => MatchScore::total($c, $c->jobOpening, MatchScore::config($c->jobOpening, $cfg))]);
        return response()->json(['data' => $rows]);
    }

    /** المعايرة: كل قيمة نسبة 0–100، الأوزان مجموعها 100، ومعيار «اكتمال الملف» يحتاج بنداً واحداً على الأقل إن كان له وزن */
    public static function validMatch(mixed $m): array
    {
        $def = Settings::DEFAULTS['match'];
        $err = [];
        $out = [];
        foreach ($def as $group => $keys) {
            foreach ($keys as $k => $dv) {
                $v = $m[$group][$k] ?? null;
                if (is_bool($dv)) { $out[$group][$k] = (bool) $v; continue; }
                $max = $k === 'bonus_years' ? 20 : ($k === 'over_penalty' ? 10 : 100);
                if (! is_numeric($v) || $v < 0 || $v > $max) { $err["match.{$group}.{$k}"] = [L::t("قيمة بين 0 و{$max}", "A value between 0 and {$max}")]; continue; }
                $out[$group][$k] = $k === 'over_penalty' ? round((float) $v, 2) : (int) $v;
            }
        }
        if (! $err && array_sum($out['weights']) !== 100) $err['match.weights'] = [L::t('مجموع الأوزان يجب أن يكون 100 (الحالي '.array_sum($out['weights']).')', 'Weights must add up to 100 (now '.array_sum($out['weights']).')')];
        if (! $err && $out['weights']['file'] > 0 && ! array_filter($out['file'])) $err['match.file'] = [L::t('اختر بنداً واحداً على الأقل لاكتمال الملف، أو اجعل وزنه 0', 'Pick at least one completeness item, or set its weight to 0')];
        if ($err) throw ValidationException::withMessages($err);
        return $out;
    }
}
