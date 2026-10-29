<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\CandidateReview;
use App\Models\Company;
use App\Services\Ai\AiClient;
use App\Services\Recruitment\CvReview;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** لوحة المنصة «قراءة السير»: حالة النموذج المحلي، وتشغيل الميزة وحدها الشهري لكل شركة، والاستهلاك */
class CvReadingController extends Controller
{
    public function show(AiClient $ai): JsonResponse
    {
        $s = CvReview::settings();
        $month = now()->startOfMonth();
        $used = CandidateReview::allCompanies()->where('created_at', '>=', $month)->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');
        $failed = CandidateReview::allCompanies()->where('created_at', '>=', $month)->where('status', 'failed')->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');
        return response()->json(['data' => [
            'configured' => (bool) config('services.ollama.url'), 'available' => $ai->available(), 'model' => $ai->model(),
            'enabled' => (bool) $s['enabled'], 'monthly_limit' => (int) $s['monthly_limit'],
            'queue' => CandidateReview::allCompanies()->whereIn('status', ['pending', 'running'])->count(),
            'companies' => Company::orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'slug'])->map(function ($c) use ($s, $used, $failed) {
                $o = $s['companies'][(string) $c->id] ?? [];
                return ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en, 'enabled' => (bool) ($o['enabled'] ?? true), 'monthly_limit' => $o['monthly_limit'] ?? null,
                    'used' => (int) ($used[$c->id] ?? 0), 'failed' => (int) ($failed[$c->id] ?? 0)];
            })->values(),
        ]]);
    }

    public function update(Request $request, AiClient $ai): JsonResponse
    {
        $v = $request->validate(['enabled' => ['required', 'boolean'], 'monthly_limit' => ['required', 'integer', 'between:0,100000'],
            'companies' => ['array'], 'companies.*.id' => ['required', 'integer'], 'companies.*.enabled' => ['required', 'boolean'], 'companies.*.monthly_limit' => ['nullable', 'integer', 'between:0,100000']]);
        $before = CvReview::settings();
        $companies = [];
        foreach ($v['companies'] ?? [] as $c) $companies[(string) $c['id']] = ['enabled' => (bool) $c['enabled'], 'monthly_limit' => $c['monthly_limit'] ?? null];
        Settings::putPlatform('ai_cv', ['enabled' => (bool) $v['enabled'], 'monthly_limit' => (int) $v['monthly_limit'], 'companies' => $companies]);
        Audit::record('platform.cv_reading', null, $before, CvReview::settings());
        return $this->show($ai);
    }
}
