<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationRequest;
use App\Services\Recruitment\ApplicationService;
use App\Services\Recruitment\DuplicateCheck;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** بوابة التوظيف العامة (M02): فحص التكرار بعد الخطوة 1، وإرسال طلب F-28 */
class ApplicationController extends Controller
{
    public function checkDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate(['national_id' => ['nullable', 'string', 'max:30'], 'email' => ['required', 'email']]);
        $dup = DuplicateCheck::find($data['national_id'] ?? null, $data['email']);
        try {
            ApplicationService::guard($dup);
        } catch (ApiException $e) {
            return response()->json(['duplicate' => $dup->exists(), 'blocked' => true, 'code' => $e->errorCode, 'field' => array_key_first($e->errors), 'message' => $e->getMessage()]);
        }
        // لا نكشف رقم الطلب السابق لمن لا يملك الحساب — يكفي إعلامه بالإلحاق وطلب كلمة مروره
        return response()->json([
            'duplicate' => $dup->exists(), 'blocked' => false, 'account' => (bool) $dup->account,
            'message' => $dup->exists() ? L::t('يوجد طلب سابق بنفس الرقم الوطني أو البريد. سيُلحق هذا الطلب بملفك الحالي، وستُطلب كلمة مرور حسابك عند الإرسال.', 'A previous application exists with this ID or email. This one will be added to your existing file, and you will be asked for your account password on submit.') : null,
        ]);
    }

    public function store(ApplicationRequest $request, ApplicationService $service): JsonResponse
    {
        $cand = $service->submit($request->validated(), $request->previousApplication(), $request->file('cv'));
        $job = $cand->jobOpening;
        return response()->json(['data' => [
            'ref' => $cand->ref, 'email' => $cand->email, 'created_at' => now()->toIso8601String(), 'merged' => $request->previousApplication()->exists(),
            'job' => ['code' => $job->code, 'title' => ['ar' => $job->title_ar, 'en' => $job->title_en]],
        ]], 201);
    }
}
