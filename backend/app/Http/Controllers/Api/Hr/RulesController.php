<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** قواعد الأتمتة (M03 — RulesModal في الديمو) + مهل المراحل، لكل شركة */
class RulesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['rules' => self::rules(), 'stage_sla' => Settings::get('stage_sla')]);
    }

    /** قواعد الشركة مكمّلة بالقيم الافتراضية للمفاتيح الجديدة (ويُسقط ما أُلغي مثل referral_points) */
    private static function rules(): array
    {
        return array_intersect_key((Settings::get('rules') ?? []) + Settings::DEFAULTS['rules'], Settings::DEFAULTS['rules']);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'rules.match_threshold' => ['required', 'integer', 'between:41,95'], 'rules.review_min' => ['required', 'integer', 'between:0,94'], 'rules.reject_max' => ['required', 'integer', 'between:0,94'],
            'rules.auto_reject' => ['required', 'boolean'], 'rules.auto_screen' => ['required', 'boolean'], 'rules.sla_alerts' => ['required', 'boolean'], 'rules.whatsapp_nudge' => ['required', 'boolean'],
            'rules.referral_stages' => ['required', 'array', 'size:4'], 'rules.referral_stages.*' => ['required', 'integer', 'between:0,10000'],
            'rules.hard_to_fill_x' => ['required', 'integer', 'between:1,5'],
            'stage_sla' => ['required', 'array'], 'stage_sla.*' => ['integer', 'between:0,60'],
        ]);
        $r = $d['rules'];
        if (! ($r['reject_max'] < $r['review_min'] && $r['review_min'] < $r['match_threshold'])) {
            return response()->json(['message' => L::t('النطاقات يجب أن تتدرج: حد الرفض < بداية المراجعة < حد الفرز.', 'Bands must ascend: reject max < review min < screening threshold.'), 'code' => 'bands_order',
                'errors' => ['rules.review_min' => [L::t('رتّب النطاقات تصاعدياً', 'Order the bands ascending')]]], 422);
        }
        $before = ['rules' => self::rules(), 'stage_sla' => Settings::get('stage_sla')];
        $r['referral_stages'] = array_map('intval', array_values($r['referral_stages']));
        Settings::put('rules', array_intersect_key($r + self::rules(), Settings::DEFAULTS['rules']));
        Settings::put('stage_sla', array_intersect_key($d['stage_sla'], Settings::DEFAULTS['stage_sla']) + Settings::get('stage_sla'));
        Audit::record('settings.recruitment_rules', null, $before, $d);
        return $this->show();
    }
}
