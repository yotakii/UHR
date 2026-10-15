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
        return response()->json(['rules' => Settings::get('rules'), 'stage_sla' => Settings::get('stage_sla')]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'rules.match_threshold' => ['required', 'integer', 'between:41,95'], 'rules.review_min' => ['required', 'integer', 'between:0,94'], 'rules.reject_max' => ['required', 'integer', 'between:0,94'],
            'rules.auto_reject' => ['required', 'boolean'], 'rules.auto_screen' => ['required', 'boolean'], 'rules.sla_alerts' => ['required', 'boolean'], 'rules.whatsapp_nudge' => ['required', 'boolean'],
            'rules.referral_points' => ['required', 'integer', 'between:0,100000'],
            'stage_sla' => ['required', 'array'], 'stage_sla.*' => ['integer', 'between:0,60'],
        ]);
        $r = $d['rules'];
        if (! ($r['reject_max'] < $r['review_min'] && $r['review_min'] < $r['match_threshold'])) {
            return response()->json(['message' => L::t('النطاقات يجب أن تتدرج: حد الرفض < بداية المراجعة < حد الفرز.', 'Bands must ascend: reject max < review min < screening threshold.'), 'code' => 'bands_order',
                'errors' => ['rules.review_min' => [L::t('رتّب النطاقات تصاعدياً', 'Order the bands ascending')]]], 422);
        }
        $before = ['rules' => Settings::get('rules'), 'stage_sla' => Settings::get('stage_sla')];
        Settings::put('rules', $r + Settings::get('rules'));
        Settings::put('stage_sla', array_intersect_key($d['stage_sla'], Settings::DEFAULTS['stage_sla']) + Settings::get('stage_sla'));
        Audit::record('settings.recruitment_rules', null, $before, $d);
        return $this->show();
    }
}
