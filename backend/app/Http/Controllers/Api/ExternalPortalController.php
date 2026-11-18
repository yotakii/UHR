<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Hr\ExternalController;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Company;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\External;
use App\Models\PaymentRequest;
use App\Models\VolunteerOpportunity;
use App\Services\Codes\Codes;
use App\Services\Externals\Externals;
use App\Services\Externals\Roster;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * بوابة غير الموظفين المبسطة (POL-VL-08 / FR-RS-015): المتطوع يرى مهامه وساعاته ووثائقه والفرص فقط،
 * والخبير يرى تكليفاته ومخرجاته ومدفوعاته ويحدّث بياناته وتوفره. لا دليل موظفين ولا رواتب ولا تقارير.
 */
class ExternalPortalController extends Controller
{
    public function __construct(private Externals $ext, private Roster $roster) {}

    private function me(Request $r): External
    {
        return External::where('user_id', $r->user()->id)->latest('id')->first() ?? throw new ApiException(L::t('لا يوجد ملف مرتبط بحسابك.', 'No record is linked to your account.'), 'not_found', 404);
    }

    public function home(Request $r): JsonResponse
    {
        $x = $this->me($r); $u = $r->user();
        $out = ['me' => ['ref' => $x->ref, 'kind' => $x->kind, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name, 'email' => $x->email, 'phone' => $x->phone, 'city' => $x->city, 'country' => $x->country, 'status' => $x->status,
            'available' => $x->available, 'available_from' => $x->available_from?->toDateString(), 'work_modes' => $x->work_modes ?? [], 'languages' => $x->languages ?? [], 'keywords' => $x->keywords ?? [], 'projects' => $x->projects,
            'review_requested' => (bool) $x->review_requested_at], 'company' => Tenant::company()?->card()];
        if (Externals::isExpert($x)) {
            $out['assignments'] = Assignment::where('external_id', $x->id)->whereNotIn('status', ['request', 'shortlist', 'cancelled'])->with('deliverables')->latest('id')->get()->map(fn ($a) => [
                'id' => $a->id, 'ref' => $a->ref, 'title' => $a->title, 'tor' => $a->tor, 'status' => $a->status, 'starts_on' => $a->starts_on?->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
                'total_fee' => $a->total_fee, 'currency' => $a->currency, 'pledges' => $a->pledges,
                'deliverables' => $a->deliverables->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'due_on' => $d->due_on?->toDateString(), 'payment' => $d->payment, 'status' => $d->status, 'note' => $d->note, 'file_id' => $d->file_id])]);
            $out['payments'] = PaymentRequest::where('external_id', $x->id)->latest('id')->get()->map(fn ($p) => ExternalController::payment($p));
        } else {
            $g = Engagement::where('external_id', $x->id)->whereNotIn('status', ['draft', 'rejected', 'cancelled'])->latest('id')->first();
            $out['engagement'] = $g ? ExternalController::engagement($g, $u) : null;
            $out['certificates'] = Engagement::where('external_id', $x->id)->whereNotNull('verify_code')->latest('id')->get(['id', 'ref', 'title', 'certificate_no', 'verify_code']);
            $out['payments'] = PaymentRequest::where('external_id', $x->id)->latest('id')->get()->map(fn ($p) => ExternalController::payment($p));
            $out['opportunities'] = VolunteerOpportunity::where('status', 'open')->latest('id')->get(['id', 'title', 'title_en', 'description', 'starts_on', 'ends_on', 'weekly_hours', 'token']);
            $out['pledges'] = Externals::cfg()['pledges'];
        }
        return response()->json($out);
    }

    private function myEngagement(Request $r, int $id): Engagement
    {
        $x = $this->me($r);
        return Engagement::where('external_id', $x->id)->findOrFail($id);
    }

    public function sign(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['pledges' => ['required', 'array'], 'signature' => ['required', 'string']]);
        $this->ext->sign($this->myEngagement($r, $id), $r->user(), $v);
        return $this->home($r);
    }

    public function log(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['date' => ['required', 'date'], 'hours' => ['required', 'numeric', 'min:0.25', 'max:16'], 'activity' => ['required', 'string', 'min:3', 'max:500']]);
        $res = $this->ext->log($this->myEngagement($r, $id), $v, $r->user());
        return response()->json(['ok' => true, 'warning' => $res['warning']], 201);
    }

    public function expense(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['amount' => ['required', 'numeric', 'min:1'], 'description' => ['required', 'string', 'max:255'], 'file_id' => ['required', 'integer']]);
        $this->ext->expense($this->myEngagement($r, $id), $v, $r->user());
        return $this->home($r);
    }

    public function certificate(Request $r, int $id): JsonResponse
    {
        $g = $this->myEngagement($r, $id);
        if (! $g->verify_code) abort(404);
        return response()->json(['data' => Externals::certificate($g)]);
    }

    public function pledge(Request $r, int $id): JsonResponse
    {
        $x = $this->me($r);
        $v = $r->validate(['kinds' => ['required', 'array'], 'conflict' => ['nullable', 'string', 'max:1000']]);
        $this->roster->pledge(Assignment::where('external_id', $x->id)->findOrFail($id), $r->user(), $v['kinds'], $v['conflict'] ?? null);
        return $this->home($r);
    }

    public function deliver(Request $r, int $id): JsonResponse
    {
        $x = $this->me($r);
        $d = Deliverable::findOrFail($id);
        Assignment::where('external_id', $x->id)->findOrFail($d->assignment_id);
        $v = $r->validate(['file_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->roster->submit($d, $r->user(), $v['file_id'], $v['note'] ?? null);
        return $this->home($r);
    }

    public function profile(Request $r): JsonResponse
    {
        $x = $this->me($r);
        $v = $r->validate(['phone' => ['nullable', 'string', 'max:40'], 'city' => ['nullable', 'string', 'max:80'], 'country' => ['nullable', 'string', 'size:2'], 'available' => ['nullable', 'in:now,from,no'],
            'available_from' => ['nullable', 'date'], 'work_modes' => ['nullable', 'array'], 'languages' => ['nullable', 'array'], 'keywords' => ['nullable', 'array'], 'projects' => ['nullable', 'string', 'max:3000']]);
        Externals::isExpert($x) ? $this->roster->selfUpdate($x, $v) : $x->update(array_intersect_key($v, array_flip(['phone', 'city'])));
        return $this->home($r);
    }

    public function upload(Request $r): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx,pptx,zip']]);
        return response()->json(ExternalController::store_file($r->file('file'), $r->user()->id), 201);
    }

    // ———————————————— عام: التسجيل والتحقق ————————————————

    public function opportunity(string $token): JsonResponse
    {
        $o = VolunteerOpportunity::allCompanies()->where('token', $token)->where('status', 'open')->firstOrFail();
        $c = Company::findOrFail($o->company_id);
        return response()->json(['data' => ['title' => $o->title, 'title_en' => $o->title_en, 'description' => $o->description, 'starts_on' => $o->starts_on?->toDateString(), 'ends_on' => $o->ends_on?->toDateString(),
            'weekly_hours' => $o->weekly_hours, 'company' => $c->card(), 'kind' => $o->kind, 'stage_type' => $o->stage_type, 'fee' => $o->fee !== null ? (float) $o->fee : null, 'fee_currency' => $o->fee_currency]]);
    }

    /** FR-VL-012: تسجيل متطوع من رابط فرصة عامة */
    public function volunteer(Request $r, string $token): JsonResponse
    {
        $o = VolunteerOpportunity::allCompanies()->where('token', $token)->where('status', 'open')->firstOrFail();
        $v = $r->validate(['name' => ['required', 'string', 'max:160'], 'name_en' => ['nullable', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:160'], 'phone' => ['required', 'string', 'max:40'],
            'gender' => ['nullable', 'in:m,f'], 'dob' => ['nullable', 'date', 'before:today'], 'city' => ['nullable', 'string', 'max:80'], 'national_id' => ['nullable', 'string', 'max:40'],
            'areas' => ['nullable', 'array'], 'availability' => ['nullable', 'array'], 'emergency' => ['nullable', 'array'], 'consent' => ['accepted']],
            ['consent.accepted' => L::t('الموافقة على معالجة البيانات مطلوبة.', 'Consent to data processing is required.')]);
        return Tenant::run($o->company_id, function () use ($v, $o) {
            $cat = Codes::resolve('worker_categories', $o->kind === 'intern' ? 'STG' : 'VOL') ?? Codes::resolve('worker_categories', 'VOL');
            $this->ext->save(collect($v)->except('consent')->all() + ['worker_category_id' => $cat?->id, 'opportunity_id' => $o->id, 'department_id' => $o->department_id, 'location_id' => $o->location_id], null, null, 'public');
            return response()->json(['ok' => true], 201);
        });
    }

    public function expertForm(string $slug): JsonResponse
    {
        $c = Company::where('slug', $slug)->where('status', 'active')->firstOrFail();
        return Tenant::run($c, fn () => response()->json(['company' => $c->card(), 'specialties' => Codes::items('specialties'), 'countries' => Codes::items('countries'), 'levels' => Roster::LEVELS]));
    }

    /** FR-RS-005: تسجيل ذاتي في قائمة الخبراء ← «متقدم» ثم تدقيق واعتماد */
    public function expert(Request $r, string $slug): JsonResponse
    {
        $c = Company::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $v = $r->validate(['entity' => ['nullable', 'in:person,firm'], 'name' => ['required', 'string', 'max:160'], 'name_en' => ['nullable', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['required', 'string', 'max:40'], 'country' => ['nullable', 'string', 'size:2'], 'city' => ['nullable', 'string', 'max:80'], 'nationality' => ['nullable', 'string', 'size:2'],
            'specialties' => ['required', 'array', 'min:1'], 'years' => ['required', 'integer', 'between:0,60'], 'level' => ['required', 'in:'.implode(',', Roster::LEVELS)], 'languages' => ['nullable', 'array'],
            'keywords' => ['nullable', 'array'], 'sectors' => ['nullable', 'array'], 'projects' => ['nullable', 'string', 'max:3000'], 'work_modes' => ['nullable', 'array'],
            'cv' => ['required', 'file', 'max:10240', 'mimes:pdf,docx'], 'consent' => ['accepted']],
            ['consent.accepted' => L::t('الموافقة على معالجة البيانات مطلوبة.', 'Consent to data processing is required.'), 'cv.required' => L::t('أرفق السيرة الذاتية.', 'Attach your CV.')]);
        return Tenant::run($c, function () use ($v, $r) {
            $file = ExternalController::store_file($r->file('cv'), null);
            $cat = Codes::resolve('worker_categories', 'EXP');
            $this->ext->save(collect($v)->except(['consent', 'cv'])->all() + ['worker_category_id' => $cat?->id, 'cv_file_id' => $file['id']], null, null, 'public');
            return response()->json(['ok' => true], 201);
        });
    }

    public function verify(string $code): JsonResponse
    {
        $d = Externals::verify($code);
        if (! $d) throw new ApiException(L::t('لا توجد شهادة بهذا الرمز.', 'No certificate has this code.'), 'not_found', 404);
        return response()->json(['data' => array_diff_key($d, ['tasks' => 1])]);
    }
}
