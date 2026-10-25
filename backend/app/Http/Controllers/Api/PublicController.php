<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicJob;
use App\Models\Company;
use App\Models\JobOpening;
use App\Models\Location;
use App\Models\Referral;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * بوابة فرص العمل العامة (SRS §10.15) وصفحات الشركات والشواغر — بلا تسجيل دخول.
 * البوابة تعرض الشواغر المنشورة على البوابة من كل الشركات؛ صفحة الشركة تعرض شواغرها المنشورة عليها.
 */
class PublicController extends Controller
{
    /** نصوص البوابة + الشركات التي توظّف الآن + قيم الفلاتر */
    public function portal(): JsonResponse
    {
        $p = Settings::platform('portal');
        $jobs = JobOpening::allCompanies()->onPortal()->with(PublicJob::WITH)->get();
        $companies = $jobs->groupBy('company_id')->map(fn ($js) => $js->first()->company->card() + ['jobs' => $js->count()])
            ->sortByDesc('jobs')->values();
        return response()->json([
            'brand_latin' => $p['brand_latin'],
            'hero' => ['title' => ['ar' => $p['hero_title_ar'], 'en' => $p['hero_title_en']], 'text' => ['ar' => $p['hero_text_ar'], 'en' => $p['hero_text_en']]],
            'stats' => ['jobs' => $jobs->count(), 'companies' => $companies->count(), 'cities' => $jobs->pluck('location_id')->unique()->count()],
            'companies' => $companies,
            'filters' => [
                'cities' => Location::whereIn('id', $jobs->pluck('location_id')->unique())->orderBy('id')->get()->map(fn ($l) => ['code' => $l->code, 'ar' => $l->name_ar, 'en' => $l->name_en])->values(),
                'types' => collect(JobOpening::TYPES)->only($jobs->pluck('employment_type')->unique())->map(fn ($t, $k) => ['code' => $k] + $t)->values(),
            ],
        ]);
    }

    /** فرص البوابة مع التصفية: الشركة، المدينة، النوع، الخبرة القصوى المطلوبة، بحث بالكلمة */
    public function jobs(Request $request): JsonResponse
    {
        $f = $request->validate(['company' => ['nullable', 'string', 'max:40'], 'city' => ['nullable', 'string', 'max:20'], 'type' => ['nullable', 'string', 'max:20'],
            'exp' => ['nullable', 'integer', 'between:0,60'], 'q' => ['nullable', 'string', 'max:80']]);
        $q = JobOpening::allCompanies()->onPortal()->with(PublicJob::WITH);
        if ($f['company'] ?? null) $q->whereHas('company', fn ($c) => $c->where('slug', $f['company']));
        if ($f['city'] ?? null) $q->whereHas('location', fn ($l) => $l->where('code', $f['city']));
        if ($f['type'] ?? null) $q->where('employment_type', $f['type']);
        if (isset($f['exp'])) $q->where('min_years', '<=', $f['exp']);
        if ($term = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.mb_strtolower($term).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(title_ar) like ?', [$like])->orWhereRaw('lower(title_en) like ?', [$like])
                ->orWhereRaw('lower(summary_ar) like ?', [$like])->orWhereRaw('lower(summary_en) like ?', [$like])
                ->orWhereHas('company', fn ($c) => $c->whereRaw('lower(name_ar) like ?', [$like])->orWhereRaw('lower(name_en) like ?', [$like])));
        }
        $jobs = $q->orderByDesc('hard_to_fill')->orderByDesc('posted_at')->orderBy('id')->limit(200)->get();
        return response()->json(['data' => $jobs->map(fn ($j) => PublicJob::card($j))]);
    }

    /** صفحة الشركة: هويتها ونبذة وشواغرها */
    public function company(string $slug): JsonResponse
    {
        $c = $this->activeCompany($slug);
        $jobs = JobOpening::allCompanies()->where('company_id', $c->id)->onCompanyPage()->with(PublicJob::WITH)->orderByDesc('posted_at')->orderBy('id')->get();
        return response()->json(['data' => $c->card() + [
            'about' => ['ar' => $c->about_ar, 'en' => $c->about_en], 'website' => $c->website,
            'jobs' => $jobs->map(fn ($j) => PublicJob::card($j)),
        ]]);
    }

    /** صفحة الشاغر — ومع ?ref= يتحقق من رابط الإحالة ويعيد اسم الموظف المُحيل */
    public function job(Request $request, string $slug, string $code): JsonResponse
    {
        $j = $this->openJob($slug, $code);
        $out = PublicJob::full($j);
        if ($ref = strtoupper(trim((string) $request->query('ref')))) {
            $r = Referral::allCompanies()->with('employee')->where('company_id', $j->company_id)->whereRaw('upper(code) = ?', [$ref])->first();
            // زيارة عبر رابط إحالة (مرة لكل جلسة)
            if ($r && ! $request->session()->has("ref-click:{$r->id}")) { $r->increment('clicks'); $request->session()->put("ref-click:{$r->id}", 1); }
            $out['referral'] = $r ? ['valid' => true, 'code' => $r->code, 'by' => ['ar' => explode(' ', $r->employee->name)[0], 'en' => explode(' ', $r->employee->name_en)[0]]] : ['valid' => false, 'code' => $ref];
        }
        return response()->json(['data' => $out]);
    }

    public function logo(string $slug): StreamedResponse|Response
    {
        $c = Company::where('slug', $slug)->first();
        if (! $c?->logo_path || ! Storage::disk('public')->exists($c->logo_path)) return response('', 404);
        return Storage::disk('public')->response($c->logo_path, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    /** خريطة الموقع لمحركات البحث: البوابة، صفحات الشركات، الشواغر المنشورة */
    public function sitemap(): Response
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $jobs = JobOpening::allCompanies()->onPortal()->with('company')->get();
        $urls = collect([['loc' => "{$base}/", 'lastmod' => now()->toDateString()], ['loc' => "{$base}/jobs", 'lastmod' => now()->toDateString()]])
            ->merge($jobs->pluck('company')->unique('id')->map(fn ($c) => ['loc' => "{$base}/c/{$c->slug}", 'lastmod' => now()->toDateString()]))
            ->merge($jobs->map(fn ($j) => ['loc' => "{$base}/jobs/{$j->company->slug}/".rawurlencode($j->code), 'lastmod' => ($j->updated_at ?? now())->toDateString()]));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$urls->map(fn ($u) => '<url><loc>'.e($u['loc']).'</loc><lastmod>'.$u['lastmod'].'</lastmod></url>')->implode('')
            .'</urlset>';
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function activeCompany(string $slug): Company
    {
        $c = Company::where('slug', $slug)->where('status', 'active')->first();
        abort_unless($c, 404, L::t('هذه الشركة غير متاحة.', 'This company is not available.'));
        return $c;
    }

    /** شاغر يقبل التقديم عبر رابطه (صفحة الشركة أو البوابة) */
    public static function openJob(string $slug, string $code): JobOpening
    {
        $j = JobOpening::allCompanies()->onCompanyPage()->with(PublicJob::WITH)->where('code', $code)
            ->whereHas('company', fn ($c) => $c->where('slug', $slug))->first();
        abort_unless($j, 404, L::t('هذه الوظيفة غير متاحة للتقديم حالياً — ربما أُغلقت أو انتهى موعدها.', 'This position is no longer open for applications — it may be closed or past its deadline.'));
        return $j;
    }
}
