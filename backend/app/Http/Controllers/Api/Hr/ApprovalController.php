<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Resources\HrCandidate;
use App\Models\Appointment;
use App\Models\AppointmentApproval;
use App\Services\Recruitment\AppointmentFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «اعتماداتي»: قرارات التعيين F-31 التي دورها عند المستخدم — يعتمدها أو يرفضها من حسابه */
class ApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = Appointment::with(['approvals', 'candidate.jobOpening.department', 'candidate.offer'])->latest('issued_at')->get()
            ->filter(fn ($ap) => ($cur = AppointmentFlow::current($ap)) && AppointmentFlow::canDecide($user, $cur))
            ->map(fn ($ap) => ['candidate' => HrCandidate::card($ap->candidate) + ['name_ar' => $ap->candidate->name, 'gender' => $ap->candidate->gender],
                'job' => ['title' => ['ar' => $ap->candidate->jobOpening->title_ar, 'en' => $ap->candidate->jobOpening->title_en], 'dept' => ['ar' => $ap->candidate->jobOpening->department->name_ar, 'en' => $ap->candidate->jobOpening->department->name_en]],
                'offer' => $ap->candidate->offer ? ['title' => $ap->candidate->offer->title, 'currency' => $ap->candidate->offer->currency, 'basic' => $ap->candidate->offer->basic, 'housing' => $ap->candidate->offer->housing,
                    'transport' => $ap->candidate->offer->transport, 'fuel' => $ap->candidate->offer->fuel, 'total' => $ap->candidate->offer->total(), 'start_date' => $ap->candidate->offer->start_date?->toDateString(),
                    'contract' => $ap->candidate->offer->contract, 'leave_days' => $ap->candidate->offer->leave_days] : null,
                'appointment' => HrCandidate::appointment($ap, $ap->candidate, $user)])->values();
        return response()->json(['data' => $items]);
    }

    public function decide(Request $request, AppointmentFlow $flow, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $ap = $flow->decide(AppointmentApproval::whereHas('appointment')->findOrFail($id), $request->user(), $data['decision'], $data['reason'] ?? null);
        return response()->json(['data' => HrCandidate::appointment($ap->load('candidate.jobOpening.department'), $ap->candidate, $request->user())]);
    }
}
