<?php

namespace App\Http\Controllers\Api\Candidate;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MyApplicationResource;
use App\Models\Candidate;
use App\Services\Recruitment\OfferResponse;
use App\Support\L;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** بوابة المتقدم (M05): طلبه فقط — المعرّف من الجلسة لا من المدخلات */
class MyApplicationController extends Controller
{
    public function show(Request $request): MyApplicationResource
    {
        return new MyApplicationResource(MyApplicationResource::load($this->candidate($request)));
    }

    public function respondOffer(Request $request, OfferResponse $service): MyApplicationResource
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
        $cand = $service->respond($this->candidate($request), $data['status'], $data['signature'] ?? null, trim((string) ($data['note'] ?? '')));
        return new MyApplicationResource(MyApplicationResource::load($cand->refresh()));
    }

    private function candidate(Request $request): Candidate
    {
        $id = $request->user()->candidate_id;
        if (! $id) throw new ApiException(L::t('لا يوجد طلب توظيف مرتبط بهذا الحساب.', 'No application is linked to this account.'), 'no_application', 404);
        return Candidate::findOrFail($id);
    }
}
