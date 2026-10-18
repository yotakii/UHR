<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Resources\HrOnboarding;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\User;
use App\Services\Onboarding\OnboardingFlow;
use App\Support\Perm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** M06 — التعيين والمباشرة: قرارات بانتظار الاعتماد، الموظفون الجدد، وصفحة مباشرة موظف (كل الإجراءات عبر OnboardingFlow) */
class OnboardingController extends Controller
{
    public function __construct(private OnboardingFlow $flow) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $joiners = $this->scoped($u)->with(HrOnboarding::WITH)->where('status', '!=', 'terminated')
            ->whereHas('onboarding', fn ($q) => $q->whereNull('confirmed_at'))->orderByDesc('hire_date')->orderByDesc('id')->get();
        $pending = Perm::can($u, 'candidates') ? Appointment::with(['approvals', 'candidate.jobOpening'])->whereNull('employee_id')
            ->whereHas('candidate', fn ($q) => $q->whereNotIn('stage', ['hired', 'rejected']))->latest('id')->get()->map(function ($ap) {
                $c = $ap->candidate; $cur = $ap->approvals->firstWhere('status', 'pending');
                return ['candidate_id' => $c->id, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'job' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en],
                    'decision_no' => $ap->decision_no, 'done' => $ap->approvals->where('status', 'approved')->count(), 'total' => $ap->approvals->count(),
                    'waiting' => $cur ? ['ar' => $cur->role_ar, 'en' => $cur->role_en] : null];
            }) : null;
        return response()->json(['joiners' => $joiners->map(fn ($e) => HrOnboarding::row($e)), 'pending' => $pending]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->out($request, $this->employee($request->user(), $id));
    }

    public function check(Request $request, int $id): JsonResponse
    {
        $i = (int) $request->validate(['index' => ['required', 'integer', 'between:0,27']])['index'];
        $e = $this->employee($request->user(), $id);
        $this->flow->toggle($e, $i, $request->user());
        return $this->out($request, $e);
    }

    public function memo(Request $request, int $id): JsonResponse
    {
        $e = $this->employee($request->user(), $id);
        $this->flow->memo($e, $request->user());
        return $this->out($request, $e);
    }

    public function issueAsset(Request $request, int $id): JsonResponse
    {
        $assetId = (int) $request->validate(['asset_id' => ['required', 'integer']])['asset_id'];
        $e = $this->employee($request->user(), $id);
        $this->flow->issueAsset($e, $assetId, $request->user());
        return $this->out($request, $e);
    }

    public function returnAsset(Request $request, int $id, int $assetId): JsonResponse
    {
        $e = $this->employee($request->user(), $id);
        $this->flow->returnAsset($e, $assetId, $request->user());
        return $this->out($request, $e);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $e = $this->employee($request->user(), $id);
        $this->flow->confirm($e, $request->user(), $note);
        return $this->out($request, $e);
    }

    private function out(Request $request, Employee $e): JsonResponse
    {
        $e = Employee::with(HrOnboarding::WITH)->findOrFail($e->id);
        return response()->json(['data' => HrOnboarding::full($e, Perm::can($request->user(), 'employees', 'edit'))]);
    }

    /** نطاق «الموظفين» للمستخدم: own خاصته، team فريقه، all الكل (SRS §10.2) */
    private function scoped(User $u): Builder
    {
        $q = Employee::query();
        return match (Perm::scope($u, 'employees')) {
            'all' => $q,
            'team' => $q->where(fn ($w) => $w->where('manager_id', $u->employee_id)->orWhere('id', $u->employee_id)),
            default => $q->where('id', $u->employee_id),
        };
    }

    private function employee(User $u, int $id): Employee
    {
        return $this->scoped($u)->whereHas('onboarding')->findOrFail($id);
    }
}
