<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\HrOnboarding;
use App\Models\Asset;
use App\Models\Employee;
use App\Services\Onboarding\OnboardingFlow;
use App\Support\L;
use App\Support\Signature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** M14 «وثائق للتوقيع»: سندات استلام العهد، F-22، F-20، إقرار ميثاق السلوك — يوقّعها الموظف من حسابه */
class MyDocumentsController extends Controller
{
    public function __construct(private OnboardingFlow $flow) {}

    public function index(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $e->loadMissing(['onboarding.f22Signature', 'onboarding.f20Signature', 'onboarding.conductSignature']);
        $ob = $e->onboarding;
        $sig = fn (string $k) => $ob ? ['signed_at' => $ob->{"{$k}_signed_at"}?->toIso8601String(), 'signature' => Signature::dataUrl($ob->{$k.'Signature'})] : null;
        $assets = Asset::with('category')->where('employee_id', $e->id)->whereIn('status', ['pending_ack', 'assigned'])->orderBy('id')->get();
        return response()->json([
            'employee' => ['name' => ['ar' => $e->name, 'en' => $e->name_en], 'code' => $e->code, 'national_id' => $e->national_id, 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'status' => $e->status],
            'undertakings' => $ob ? ['f22' => $sig('f22'), 'f20' => [...$sig('f20'), 'mother_name' => $ob->f20_mother_name], 'conduct' => $sig('conduct')] : null,
            'pending' => $assets->where('status', 'pending_ack')->values()->map(fn ($a) => HrOnboarding::asset($a)),
            'signed' => $assets->where('status', 'assigned')->values()->map(fn ($a) => HrOnboarding::asset($a)),
        ]);
    }

    public function sign(Request $request): JsonResponse
    {
        $d = $request->validate([
            'doc' => ['required', Rule::in(['f22', 'f20', 'conduct', 'asset'])], 'signature' => ['nullable', 'string', 'max:450000'],
            'asset_id' => ['required_if:doc,asset', 'integer'], 'mother_name' => ['nullable', 'string', 'max:120'],
        ]);
        $this->flow->sign($this->me($request), $d['doc'], $d['signature'] ?? null, $d);
        return $this->index($request);
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        abort_unless($id, 403, L::t('هذه الصفحة للموظفين فقط.', 'This page is for employees only.'));
        return Employee::findOrFail($id);
    }
}
