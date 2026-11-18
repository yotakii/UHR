<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicJob;
use App\Models\Company;
use App\Models\JobOpening;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * كود تضمين شواغر الشركة في موقعها (FEATURES 05b — اختياري): صفحة HTML مستقلة خفيفة داخل إطار،
 * تعرض شواغر «صفحة الشركة» نفسها (لا شيء داخلي)، وتفتح التقديم في بوابة الشواغر. المواقع المسموح لها
 * بالتضمين تُفرض بترويسة frame-ancestors، وسكربت الإدراج يضبط ارتفاع الإطار تلقائياً.
 */
class EmbedController extends Controller
{
    public static function cfg(?int $companyId = null): array
    {
        return $companyId ? Tenant::run($companyId, fn () => Settings::merged('embed')) : Settings::merged('embed');
    }

    /** يحوّل ما يكتبه المستخدم (رابط أو اسم نطاق) إلى اسم نطاق نظيف أو null */
    public static function host(string $s): ?string
    {
        $s = strtolower(trim($s));
        if ($s === '') return null;
        $h = parse_url(str_contains($s, '://') ? $s : "https://{$s}", PHP_URL_HOST);
        $h = $h ? preg_replace('/^\*\./', '', $h) : null;
        return $h && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$|^localhost$/', $h) ? $h : null;
    }

    public function page(Request $r, string $slug): Response
    {
        $c = PublicController::activeCompany($slug);
        $cfg = self::cfg($c->id);
        abort_unless($cfg['enabled'], 404);
        $lang = $r->query('lang') === 'en' ? 'en' : 'ar';
        $theme = in_array($r->query('theme'), ['light', 'dark'], true) ? $r->query('theme') : $cfg['theme'];
        $limit = max(1, min(100, (int) ($r->query('limit') ?: $cfg['limit'])));
        $jobs = JobOpening::allCompanies()->where('company_id', $c->id)->onCompanyPage()->with(PublicJob::WITH)->orderByDesc('posted_at')->orderBy('id')->get()
            ->map(fn ($j) => PublicJob::card($j));
        if ($d = $r->query('dept')) $jobs = $jobs->filter(fn ($j) => strcasecmp($j['dept']['code'], (string) $d) === 0);
        if ($ci = $r->query('city')) $jobs = $jobs->filter(fn ($j) => strcasecmp($j['city']['code'], (string) $ci) === 0);
        $total = $jobs->count();
        $base = rtrim((string) config('app.frontend_url'), '/');
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($cfg['color'] ?: $c->brand_color)) ? ($cfg['color'] ?: $c->brand_color) : '#2f6f5e';
        $html = view('embed.jobs', [
            'c' => $c->card(), 'lang' => $lang, 'theme' => $theme, 'color' => $color, 'jobs' => $jobs->take($limit)->values(), 'total' => $total,
            'more' => $total > $limit, 'base' => $base, 'slug' => $c->slug, 'logo' => $cfg['show_logo'] && $c->logo_path, 'filters' => $cfg['show_filters'] && $total > 3,
            't' => fn (string $ar, string $en) => $lang === 'en' ? $en : $ar,
        ])->render();
        $sites = array_values(array_filter(array_map(fn ($s) => self::host((string) $s), $cfg['sites'])));
        $ancestors = $sites ? "'self' ".implode(' ', array_map(fn ($h) => "https://{$h} https://*.{$h} http://{$h}", $sites)) : '*';
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "frame-ancestors {$ancestors}",
            'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin']);
    }

    /** سكربت الإدراج: <div data-uhr-jobs="slug"></div><script src=".../embed.js" async></script> */
    public function script(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $js = <<<JS
(function () {
  var src = document.currentScript && document.currentScript.src, origin = src ? new URL(src).origin : '{$base}';
  function mount(el) {
    if (el.getAttribute('data-uhr-ready')) return; el.setAttribute('data-uhr-ready', '1');
    var q = new URLSearchParams(); ['lang', 'theme', 'dept', 'city', 'limit'].forEach(function (k) { var v = el.getAttribute('data-' + k); if (v) q.set(k, v); });
    var f = document.createElement('iframe');
    f.src = origin + '/api/public/embed/' + encodeURIComponent(el.getAttribute('data-uhr-jobs')) + (q.toString() ? '?' + q : '');
    f.title = el.getAttribute('data-title') || 'Jobs'; f.loading = 'lazy'; f.setAttribute('scrolling', 'no');
    f.style.cssText = 'width:100%;border:0;display:block;min-height:240px;color-scheme:normal';
    el.appendChild(f);
    window.addEventListener('message', function (e) {
      if (e.origin !== origin || e.source !== f.contentWindow || !e.data || e.data.type !== 'uhr-embed-height') return;
      f.style.height = Math.max(120, Math.min(20000, +e.data.height || 0)) + 'px';
    });
  }
  function run() { Array.prototype.forEach.call(document.querySelectorAll('[data-uhr-jobs]'), mount); }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', run) : run();
})();
JS;
        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }

    public function settings(Request $r): JsonResponse
    {
        $c = Tenant::company();
        $base = rtrim((string) config('app.url'), '/');
        return response()->json(['data' => self::cfg(), 'defaults' => Settings::DEFAULTS['embed'], 'slug' => $c->slug,
            'urls' => ['script' => "{$base}/api/public/embed.js", 'page' => "{$base}/api/public/embed/{$c->slug}"],
            'departments' => \App\Models\Department::where('active', true)->orderBy('sort')->get(['code', 'name_ar', 'name_en'])->map(fn ($d) => ['code' => $d->code, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'can' => ['edit' => \App\Support\Perm::can($r->user(), 'settings', 'edit')]]);
    }

    public function save(Request $r): JsonResponse
    {
        $v = $r->validate(['enabled' => ['required', 'boolean'], 'sites' => ['present', 'array', 'max:20'], 'sites.*' => ['nullable', 'string', 'max:255'], 'limit' => ['required', 'integer', 'between:1,100'],
            'theme' => ['required', 'in:light,dark'], 'show_logo' => ['required', 'boolean'], 'show_filters' => ['required', 'boolean'], 'color' => ['nullable', 'regex:/^(#[0-9a-fA-F]{6})?$/']]);
        $sites = [];
        foreach ($v['sites'] as $i => $s) {
            if (trim((string) $s) === '') continue;
            $h = self::host((string) $s);
            if (! $h) throw ApiException::field("sites.{$i}", L::t('اكتب عنوان موقع صحيحاً مثل example.com', 'Enter a valid site such as example.com'), 'invalid_site');
            $sites[] = $h;
        }
        $v['sites'] = array_values(array_unique($sites)); $v['color'] = (string) ($v['color'] ?? '');
        $old = self::cfg();
        Settings::put('embed', $v);
        Audit::record('settings.embed', null, ['before' => $old, 'after' => $v]);
        return $this->settings($r);
    }
}
