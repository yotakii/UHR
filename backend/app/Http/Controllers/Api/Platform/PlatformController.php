<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicJob;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Services\Platform\CompanyProvisioner;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * مدير المنصة (Atmata — SRS §10.15): الشركات، مراجعة الشواغر قبل البوابة، نصوص البوابة، الإحصاءات.
 * لا يصل لملفات المتقدمين ولا لبيانات الموظفين داخل الشركات — أعداد فقط.
 */
class PlatformController extends Controller
{
    public function overview(): JsonResponse
    {
        $since = now()->subDays(30);
        return response()->json([
            'companies' => Company::count(), 'active' => Company::where('status', 'active')->count(),
            'portal_jobs' => JobOpening::allCompanies()->onPortal()->count(),
            'pending' => JobOpening::allCompanies()->where('visibility', 'public')->where('portal_status', 'pending')->where('status', 'open')->count(),
            'applications' => Candidate::allCompanies()->count(), 'applications_30d' => Candidate::allCompanies()->where('created_at', '>=', $since)->count(),
            'by_company' => Company::orderBy('id')->get()->map(fn ($c) => $c->card() + [
                'applications' => Candidate::allCompanies()->where('company_id', $c->id)->count(),
                'applications_30d' => Candidate::allCompanies()->where('company_id', $c->id)->where('created_at', '>=', $since)->count(),
                'jobs' => JobOpening::allCompanies()->where('company_id', $c->id)->onPortal()->count(),
            ]),
        ]);
    }

    public function companies(): JsonResponse
    {
        $companies = Company::orderBy('id')->get();
        return response()->json(['data' => $companies->map(fn (Company $c) => $this->presentCompany($c))]);
    }

    public function storeCompany(Request $request, CompanyProvisioner $prov): JsonResponse
    {
        $data = $this->validateCompany($request, null, ['admin_email' => ['required', 'email', 'unique:users,email']]);
        $admin = strtolower(trim($data['admin_email']));
        unset($data['admin_email']);
        [$company, $temp] = $prov->create($data + ['status' => 'active'], $admin, $request->file('logo'));
        Audit::record('platform.company_created', $company, null, ['slug' => $company->slug, 'admin' => $admin]);
        return response()->json(['data' => $this->presentCompany($company), 'admin' => ['email' => $admin, 'temporary_password' => $temp]], 201);
    }

    public function updateCompany(Request $request, CompanyProvisioner $prov, int $id): JsonResponse
    {
        $c = Company::findOrFail($id);
        $c->update($this->validateCompany($request, $c));
        if ($request->file('logo')) $prov->setLogo($c, $request->file('logo'));
        return response()->json(['data' => $this->presentCompany($c->refresh())]);
    }

    public function companyStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => ['required_if:status,suspended', 'nullable', 'string', 'min:10', 'max:500']], $this->reasonMessages());
        $c = Company::findOrFail($id);
        if ($c->is_platform && $data['status'] === 'suspended') throw new ApiException(L::t('لا يمكن تعليق الشركة المالكة للمنصة.', 'The platform owner cannot be suspended.'), 'platform_company');
        $c->update(['status' => $data['status']]);
        Audit::record('platform.company_'.$data['status'], $c, null, ['reason' => $data['reason'] ?? null]);
        return response()->json(['data' => $this->presentCompany($c)]);
    }

    /** شواغر الشركات العامة حسب حالة المراجعة (افتراضياً: بانتظار المراجعة) */
    public function reviews(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]])['status'] ?? 'pending';
        $jobs = JobOpening::allCompanies()->with(PublicJob::WITH)->where('visibility', 'public')->where('portal_status', $status)
            ->when($status === 'pending', fn ($q) => $q->where('status', 'open'))->orderBy('updated_at')->limit(200)->get();
        return response()->json(['data' => $jobs->map(fn ($j) => PublicJob::full($j) + [
            'id' => $j->id, 'status' => $j->status, 'portal_status' => $j->portal_status, 'portal_note' => $j->portal_note,
            'portal_reviewed_at' => $j->portal_reviewed_at?->toIso8601String(), 'submitted_at' => $j->updated_at?->toIso8601String(),
            'salary' => ['min' => $j->salary_min, 'max' => $j->salary_max, 'currency' => $j->currency],
        ])]);
    }

    /** قرار المراجعة — الرفض بسبب إلزامي يصل لموارد الشركة البشرية (SRS §10.1) */
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'min:10', 'max:500']], $this->reasonMessages());
        $job = JobOpening::allCompanies()->with('company')->findOrFail($id);
        if ($job->visibility !== 'public') throw new ApiException(L::t('هذا الشاغر غير مطلوب نشره على البوابة.', 'This position is not set for the public portal.'), 'not_public');
        $job->update(['portal_status' => $data['decision'], 'portal_note' => $data['reason'] ?? null, 'portal_reviewed_at' => now(), 'portal_reviewed_by' => $request->user()->id]);
        Tenant::run($job->company_id, fn () => $data['decision'] === 'approved'
            ? Notify::hr("نُشر الشاغر «{$job->title_ar}» على بوابة فرص العمل", "«{$job->title_en}» is now live on the jobs portal", "job:{$job->id}")
            : Notify::hr("لم يُنشر الشاغر «{$job->title_ar}» على البوابة: {$data['reason']}", "«{$job->title_en}» was not published on the portal: {$data['reason']}", "job:{$job->id}"));
        return response()->json(['data' => ['id' => $job->id, 'portal_status' => $job->portal_status, 'portal_note' => $job->portal_note]]);
    }

    public function portal(): JsonResponse
    {
        return response()->json(['data' => Settings::platform('portal')]);
    }

    public function updatePortal(Request $request): JsonResponse
    {
        $data = $request->validate(['brand_latin' => ['required', 'string', 'max:60'], 'hero_title_ar' => ['required', 'string', 'max:120'], 'hero_title_en' => ['required', 'string', 'max:120'],
            'hero_text_ar' => ['required', 'string', 'max:400'], 'hero_text_en' => ['required', 'string', 'max:400']],
            ['required' => L::t('هذا الحقل مطلوب', 'This field is required'), 'max' => L::t('النص أطول من المسموح', 'Text is too long')]);
        Settings::putPlatform('portal', $data);
        Audit::record('platform.portal_updated', null, null, $data);
        return response()->json(['data' => Settings::platform('portal')]);
    }

    private function validateCompany(Request $request, ?Company $c = null, array $extra = []): array
    {
        $data = $request->validate($extra + [
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('companies', 'slug')->ignore($c?->id)],
            'name_ar' => ['required', 'string', 'max:150'], 'name_en' => ['required', 'string', 'max:150'],
            'short_ar' => ['nullable', 'string', 'max:80'], 'short_en' => ['nullable', 'string', 'max:80'], 'brand_latin' => ['nullable', 'string', 'max:60'],
            'about_ar' => ['nullable', 'string', 'max:1000'], 'about_en' => ['nullable', 'string', 'max:1000'], 'website' => ['nullable', 'url', 'max:200'],
            'portal_enabled' => ['boolean'], 'portal_review' => ['boolean'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ], [
            'required' => L::t('هذا الحقل مطلوب', 'This field is required'),
            'slug.regex' => L::t('أحرف إنكليزية صغيرة وأرقام وشرطة، ويبدأ بحرف', 'Lowercase letters, digits and dashes, starting with a letter'),
            'slug.unique' => L::t('هذا الاسم مستخدم — جرّب اسماً آخر', 'This name is taken — try another'), 'slug.min' => L::t('3 أحرف على الأقل', 'At least 3 characters'),
            'admin_email.unique' => L::t('هذا البريد مستخدم لحساب آخر.', 'This email is already used by another account.'), 'admin_email.email' => L::t('صيغة البريد غير صحيحة.', 'Invalid email format.'),
            'website.url' => L::t('رابط غير صالح', 'Invalid URL'), 'logo.max' => L::t('الحد الأقصى 1MB', 'Maximum 1MB'), 'logo.mimes' => L::t('PNG أو JPG أو WEBP', 'PNG, JPG or WEBP'),
        ]);
        unset($data['logo']);
        return $data;
    }

    private function reasonMessages(): array
    {
        return ['reason.required_if' => L::t('اكتب سبب القرار.', 'Write the reason for this decision.'), 'reason.min' => L::t('السبب 10 أحرف على الأقل.', 'The reason must be at least 10 characters.')];
    }

    private function presentCompany(Company $c): array
    {
        return $c->card() + [
            'id' => $c->id, 'status' => $c->status, 'portal_enabled' => $c->portal_enabled, 'portal_review' => $c->portal_review, 'is_platform' => $c->is_platform,
            'about' => ['ar' => $c->about_ar, 'en' => $c->about_en], 'website' => $c->website, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en,
            'short_ar' => $c->short_ar, 'short_en' => $c->short_en, 'about_ar' => $c->about_ar, 'about_en' => $c->about_en,
            'counts' => [
                'jobs_open' => JobOpening::allCompanies()->where('company_id', $c->id)->where('status', 'open')->count(),
                'jobs_portal' => JobOpening::allCompanies()->where('company_id', $c->id)->onPortal()->count(),
                'jobs_pending' => JobOpening::allCompanies()->where('company_id', $c->id)->where('visibility', 'public')->where('portal_status', 'pending')->count(),
                'applications' => Candidate::allCompanies()->where('company_id', $c->id)->count(),
                'employees' => Employee::allCompanies()->where('company_id', $c->id)->where('status', '!=', 'terminated')->count(),
            ],
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
