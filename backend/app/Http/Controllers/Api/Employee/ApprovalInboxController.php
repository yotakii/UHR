<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequestRow;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\RequestStep;
use App\Services\Leave\LeaveService;
use App\Services\Leave\OvertimeService;
use App\Services\Workflow\Workflow;
use App\Support\L;
use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * صندوق الاعتماد في المسار الموحد (SRS §10.1) — للمدير (فريقه)، والموارد البشرية، والمستويات العليا، من أي بوابة:
 * الطلبات بانتظار قرار المستخدم، والقرار بخطوتين (DecisionDialog)، ونائب المدير، وتكليف الفريق بالإضافي F-04.
 */
class ApprovalInboxController extends Controller
{
    public function __construct(private LeaveService $leaves, private OvertimeService $overtime) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user()->loadMissing('roles');
        $rows = Workflow::inbox($u)->map(function (RequestStep $s) use ($u) {
            $x = $s->subject;
            return $x instanceof LeaveRequest ? RequestRow::leave($x->load(RequestRow::WITH), $u) : RequestRow::overtime($x->load(['employee', 'requester.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee']), $u);
        })->values();
        $me = $u->employee_id ? Employee::with('delegate')->find($u->employee_id) : null;
        $team = $me ? Employee::current()->where('manager_id', $me->id)->orderBy('name')->get() : collect();
        return response()->json(['data' => $rows, 'reject_min' => (int) LeaveService::rules()['reject_min'],
            'team' => $team->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en])->values(),
            'delegate' => $me?->delegate ? ['id' => $me->delegate->id, 'ar' => $me->delegate->name, 'en' => $me->delegate->name_en] : null,
            'delegates' => $me ? Employee::current()->where('department_id', $me->department_id)->where('id', '!=', $me->id)->orderBy('name')->get()->map(fn ($e) => ['id' => $e->id, 'ar' => $e->name, 'en' => $e->name_en])->values() : [],
            'overtime_max' => (int) \App\Support\Settings::policy('overtime')['daily_max'],
            'assigned' => $me ? OvertimeRequest::with(['employee', 'requester.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'])->where('requested_by', $u->id)->latest('id')->limit(30)->get()->map(fn ($o) => RequestRow::overtime($o, $u)) : []]);
    }

    public function decide(Request $request, int $stepId): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:1000']]);
        $s = RequestStep::with('subject')->findOrFail($stepId);
        $x = $s->subject ?? abort(404);
        $u = $request->user()->loadMissing('roles');
        $ok = $d['decision'] === 'approve';
        if ($x instanceof LeaveRequest) $this->leaves->decide($x, $u, $ok, $d['note'] ?? null);
        elseif ($x instanceof OvertimeRequest) $this->overtime->decide($x, $u, $ok, $d['note'] ?? null);
        else abort(404);
        return $this->index($request);
    }

    /** نائب المدير من إدارته — يستلم طلبات فريقه أثناء إجازته */
    public function delegate(Request $request): JsonResponse
    {
        $id = $request->validate(['employee_id' => ['nullable', 'integer']])['employee_id'] ?? null;
        $me = Employee::findOrFail($request->user()->employee_id ?? 0);
        if ($id && ! Employee::current()->where('department_id', $me->department_id)->whereKey($id)->where('id', '!=', $me->id)->exists()) {
            throw ApiException::field('employee_id', L::t('النائب من إدارتك.', 'The deputy must be from your department.'), 'delegate_dept');
        }
        $me->update(['delegate_id' => $id]);
        $me->log($id ? 'تسمية نائب: '.Employee::find($id)->name : 'إلغاء النائب', $id ? 'Deputy named: '.Employee::find($id)->name_en : 'Deputy removed');
        return $this->index($request);
    }

    public function assignOvertime(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'], 'minutes' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->overtime->assign($request->user()->loadMissing('roles'), Employee::current()->findOrFail($d['employee_id']), $d['date'], (int) $d['minutes'], trim($d['reason']));
        return $this->index($request);
    }
}
