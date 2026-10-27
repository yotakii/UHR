<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyProvisioner;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M16 «بيانات الشركة»: الهوية (الاسم، الاسم المختصر، الاسم اللاتيني، النبذة، الموقع، الشعار)، بيانات النماذج (بادئة رمز النموذج،
 * اسم إدارة الموارد البشرية، النطاق، رابط التوظيف)، نصوص الصفحة العامة، وبادئات الترقيم. الرابط المختصر (slug) والحالة من لوحة المنصة.
 */
class CompanyController extends Controller
{
    public function show(): JsonResponse
    {
        $c = Tenant::company();
        $s = array_merge(Settings::DEFAULTS['company'], Settings::get('company') ?? []);
        return response()->json(['data' => [
            'slug' => $c->slug, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'short_ar' => $c->short_ar, 'short_en' => $c->short_en, 'brand_latin' => $c->brand_latin,
            'about_ar' => $c->about_ar, 'about_en' => $c->about_en, 'website' => $c->website, 'logo' => $c->card()['logo'] ?? null,
            'domain' => $s['domain'] ?? '', 'careers_url' => $s['careers_url'] ?? '', 'form_prefix' => $s['form_prefix'] ?? '', 'hr_dept_ar' => $s['hr_dept_ar'] ?? '', 'hr_dept_en' => $s['hr_dept_en'] ?? '',
            'site' => array_merge(Settings::DEFAULTS['site'], Settings::get('site') ?? []),
            'prefixes' => array_merge(Settings::DEFAULTS['prefixes'], Settings::get('prefixes') ?? []),
        ], 'defaults' => ['site' => Settings::DEFAULTS['site'], 'prefixes' => Settings::DEFAULTS['prefixes']]]);
    }

    public function update(Request $request, CompanyProvisioner $prov): JsonResponse
    {
        $p = ['employee', 'candidate', 'decision', 'asset', 'job'];
        $request->merge(['website' => \App\Support\Url::normalize($request->input('website')), 'careers_url' => \App\Support\Url::normalize($request->input('careers_url'))]);
        $d = $request->validate([
            'name_ar' => ['required', 'string', 'max:160'], 'name_en' => ['required', 'string', 'max:160'], 'short_ar' => ['nullable', 'string', 'max:80'], 'short_en' => ['nullable', 'string', 'max:80'],
            'brand_latin' => ['nullable', 'string', 'max:60'], 'about_ar' => ['nullable', 'string', 'max:2000'], 'about_en' => ['nullable', 'string', 'max:2000'], 'website' => ['nullable', 'url', 'max:190'],
            'domain' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'], 'careers_url' => ['nullable', 'url', 'max:190'], 'form_prefix' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'hr_dept_ar' => ['required', 'string', 'max:120'], 'hr_dept_en' => ['required', 'string', 'max:120'],
            'site' => ['required', 'array'], 'site.hero_title_ar' => ['required', 'string', 'max:160'], 'site.hero_title_en' => ['required', 'string', 'max:160'],
            'site.hero_text_ar' => ['required', 'string', 'max:600'], 'site.hero_text_en' => ['required', 'string', 'max:600'],
            'prefixes' => ['required', 'array'], ...collect($p)->mapWithKeys(fn ($k) => ["prefixes.$k" => ['required', 'string', 'regex:/^[A-Z]{2,6}$/']])->all(),
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], ['prefixes.*.regex' => L::t('البادئة 2–6 أحرف إنكليزية كبيرة.', 'Prefixes are 2–6 capital letters.')],
            ['name_ar' => L::t('الاسم بالعربية', 'Arabic name'), 'name_en' => L::t('الاسم بالإنكليزية', 'English name'), 'form_prefix' => L::t('بادئة رمز النموذج', 'Form code prefix')]);
        $c = Tenant::company();
        $c->update(array_intersect_key($d, array_flip(['name_ar', 'name_en', 'short_ar', 'short_en', 'brand_latin', 'about_ar', 'about_en', 'website'])));
        if ($request->file('logo')) $prov->setLogo($c, $request->file('logo'));
        $before = ['company' => Settings::get('company'), 'site' => Settings::get('site'), 'prefixes' => Settings::get('prefixes')];
        $company = array_merge(Settings::get('company') ?? Settings::DEFAULTS['company'], ['name_ar' => $d['name_ar'], 'name_en' => $d['name_en'], 'short_ar' => $d['short_ar'] ?? '', 'short_en' => $d['short_en'] ?? '',
            'brand_latin' => $d['brand_latin'] ?? '', 'domain' => $d['domain'] ?? '', 'careers_url' => $d['careers_url'] ?? '', 'form_prefix' => $d['form_prefix'], 'hr_dept_ar' => $d['hr_dept_ar'], 'hr_dept_en' => $d['hr_dept_en']]);
        Settings::put('company', $company);
        Settings::put('site', array_intersect_key($d['site'], Settings::DEFAULTS['site']));
        Settings::put('prefixes', array_intersect_key($d['prefixes'], Settings::DEFAULTS['prefixes']));
        Audit::record('settings.company', null, $before, ['company' => $company, 'site' => $d['site'], 'prefixes' => $d['prefixes']]);
        return $this->show();
    }
}
