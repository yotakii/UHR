<?php

namespace App\Http\Controllers\Api\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyApplicationResource;
use App\Models\Candidate;
use App\Exceptions\ApiException;
use App\Services\Cv\CvDatabase;
use App\Services\Recruitment\OfferResponse;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * بوابة المتقدم (M05 + §10.15): كل طلبات الحساب لدى كل الشركات — المعرّف من الجلسة لا من المدخلات.
 */
class MyApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = $request->user()->candidates()->with(['jobOpening.company', 'offer', 'appointment.approvals'])->latest('created_at')->latest('id')->get();
        return response()->json(['data' => $list->map(fn (Candidate $c) => [
            'id' => $c->id, 'ref' => $c->ref, 'stage' => $c->stage, 'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
            'company' => $c->jobOpening->company->card(),
            'job' => ['code' => $c->jobOpening->code, 'title' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en]],
            'offer' => $c->offer?->status, 'appointment' => $c->appointment ? ['complete' => $c->appointment->isComplete()] : null,
        ])]);
    }

    public function show(Request $request, int $id): MyApplicationResource
    {
        return new MyApplicationResource(MyApplicationResource::load($this->candidate($request, $id)));
    }

    /** آخر بيانات F-28 لتعبئة الطلب التالي تلقائياً (لا تُنسخ الملفات ولا الإقرارات) */
    public function profile(Request $request): JsonResponse
    {
        $c = $request->user()->candidates()->latest('updated_at')->latest('id')->first();
        $user = $request->user();
        if (! $c) return response()->json(['data' => ['name' => $user->name, 'name_en' => $user->name_en, 'email' => $user->email]]);
        $edu = $c->education[0] ?? [];
        return response()->json(['data' => [
            'name' => $c->name, 'name_en' => $c->name_en, 'gender' => $c->gender, 'marital' => $c->marital, 'nationality' => $c->nationality, 'dob' => $c->dob?->toDateString(),
            'pob' => $c->pob, 'national_id' => $c->national_id, 'blood' => $c->blood, 'mobile' => $c->mobile, 'email' => $user->email, 'address' => $c->address, 'linkedin' => $c->linkedin,
            'degree' => $c->degree_level, 'institute' => $edu['institute'] ?? '', 'major' => $edu['major'] ?? '', 'grad_year' => $edu['year'] ?? '', 'grade' => $edu['grade'] ?? '',
            'english' => $c->english, 'years_exp' => $c->years_exp, 'experience' => $c->experience, 'ref' => $c->refs[0] ?? null,
            'shifts' => $c->shifts, 'travel' => $c->travel, 'public_sector' => $c->public_sector, 'conviction' => $c->conviction, 'salary' => $c->salary,
        ]]);
    }

    public function respondOffer(Request $request, OfferResponse $service, int $id): MyApplicationResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'declined', 'negotiation'])],
            'signature' => ['nullable', 'string'],
            'note' => [Rule::requiredIf($request->input('status') === 'negotiation'), 'nullable', 'string', 'max:1000'],
        ], [
            'status.*' => L::t('اختر ردّك على العرض.', 'Choose your response to the offer.'),
            'note.required' => L::t('اكتب البنود التي تطلب تعديلها.', 'Write the terms you would like changed.'),
            'note.max' => L::t('النص أطول من المسموح.', 'Text is too long.'),
        ]);
        $cand = $this->candidate($request, $id);
        // الرد يُكتب داخل شركة الطلب: سلسلة الاعتماد والترقيم والإشعارات من إعداداتها
        Tenant::run($cand->company_id, fn () => $service->respond($cand, $data['status'], $data['signature'] ?? null, trim((string) ($data['note'] ?? ''))));
        return new MyApplicationResource(MyApplicationResource::load($cand->refresh()));
    }

    /** «احذف بياناتي» (D-05): بكلمة مرور الحساب — تُمسح الملفات والبيانات الشخصية ويبقى أثر مجهّل لدى الشركة */
    public function erase(Request $request, CvDatabase $db, int $id): JsonResponse
    {
        $pw = $request->validate(['password' => ['required', 'string']], ['password.required' => L::t('أدخل كلمة مرور حسابك للتأكيد.', 'Enter your account password to confirm.')])['password'];
        if (! Hash::check($pw, $request->user()->password)) throw ApiException::field('password', L::t('كلمة المرور غير صحيحة.', 'The password is incorrect.'), 'bad_password');
        $cand = $this->candidate($request, $id);
        Tenant::run($cand->company_id, fn () => $db->erase($cand));
        return response()->json(['ok' => true]);
    }

    private function candidate(Request $request, int $id): Candidate
    {
        return $request->user()->candidates()->findOrFail($id);
    }
}
