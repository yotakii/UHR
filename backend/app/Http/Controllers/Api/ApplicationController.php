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

/** التقديم على شاغر شركة (M02 + §10.15): فحص التكرار بعد الخطوة 1، وإرسال F-28 */
class ApplicationController extends Controller
{
    public function checkDuplicate(Request $request, string $slug, string $code): JsonResponse
    {
        $job = PublicController::openJob($slug, $code);
        $data = $request->validate(['national_id' => ['nullable', 'string', 'max:30'], 'email' => ['required', 'email']]);
        $dup = DuplicateCheck::find($job, $data['national_id'] ?? null, $data['email'], $request->user());
        try {
            ApplicationService::guard($dup);
        } catch (ApiException $e) {
            return response()->json(['duplicate' => $dup->exists(), 'blocked' => true, 'code' => $e->errorCode, 'field' => array_key_first($e->errors), 'message' => $e->getMessage()]);
        }
        // لا نكشف رقم الطلب السابق لمن لا يملك الحساب — يكفي إعلامه بالإلحاق وطلب كلمة مروره
        $msg = $dup->exists()
            ? L::t('يوجد طلب سابق لك لدى هذه الشركة. سيُلحق هذا الطلب بملفك الحالي.', 'You have a previous application with this company. This one will be added to your existing file.')
            : ($dup->needsPassword() ? L::t('لديك حساب على المنصة بهذا البريد. سيُضاف هذا الطلب لحسابك بعد إدخال كلمة مروره.', 'You already have an account with this email. This application will be added to it after you enter its password.') : null);
        return response()->json(['duplicate' => $dup->exists(), 'blocked' => false, 'account' => $dup->needsPassword(), 'signed_in' => $dup->signedIn, 'message' => $msg]);
    }

    public function store(ApplicationRequest $request, ApplicationService $service): JsonResponse
    {
        $dup = $request->previousApplication();
        $cand = $service->submit($request->validated(), $dup, $request->file('cv'), $request->file('photo'));
        $job = $cand->jobOpening;
        return response()->json(['data' => [
            'id' => $cand->id, 'ref' => $cand->ref, 'email' => $cand->email, 'created_at' => now()->toIso8601String(), 'merged' => $dup->exists(),
            'job' => ['code' => $job->code, 'title' => ['ar' => $job->title_ar, 'en' => $job->title_en]], 'company' => $job->company->card(),
        ]], 201);
    }
}
