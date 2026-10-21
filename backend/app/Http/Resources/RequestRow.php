<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Leave\LeaveService;
use App\Services\Workflow\Workflow;

/** صف طلب في المسار الموحد (إجازة، تبرير، تكليف إضافي) مع خطواته وهل ينتظر قرار المشاهد */
class RequestRow
{
    public static function leave(LeaveRequest $l, ?User $viewer = null): array
    {
        $t = $l->type === 'justify' ? null : LeaveService::type($l->type);
        $cur = Workflow::current($l);
        return ['kind' => $l->type === 'justify' ? 'justify' : 'leave', 'id' => $l->id, 'type' => $l->type,
            'type_name' => ['ar' => $l->workflowLabel('ar'), 'en' => $l->workflowLabel('en')],
            'employee' => self::person($l->employee), 'from' => $l->from->toDateString(), 'to' => $l->to->toDateString(), 'hours' => $l->hours, 'minutes' => $l->minutes,
            'ref_date' => $l->ref_date?->toDateString(), 'days' => $l->days, 'reason' => $l->reason, 'status' => $l->status, 'warnings' => $l->warnings ?? [],
            'substitute' => $l->substitute ? ['ar' => $l->substitute->name, 'en' => $l->substitute->name_en] : null,
            'doc' => $l->doc_file_id ? ['id' => $l->doc_file_id, 'name' => $l->doc?->name] : null, 'doc_required' => ($t['doc'] ?? 'none') === 'required',
            'paid' => $t['paid'] ?? null, 'created_at' => $l->created_at?->toIso8601String(), 'steps' => Workflow::steps($l),
            'step_id' => $cur?->id, 'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $l) : false,
            'can_cancel' => $viewer && $cur && ($viewer->employee_id === $l->employee_id || $viewer->id === $l->requested_by) && $cur->level === (int) $l->steps->whereNotIn('status', ['skipped'])->min('level')];
    }

    public static function overtime(OvertimeRequest $o, ?User $viewer = null): array
    {
        $cur = Workflow::current($o);
        return ['kind' => 'overtime', 'id' => $o->id, 'type' => 'overtime', 'type_name' => ['ar' => $o->workflowLabel('ar'), 'en' => $o->workflowLabel('en')],
            'employee' => self::person($o->employee), 'from' => $o->date->toDateString(), 'to' => $o->date->toDateString(), 'minutes' => $o->minutes, 'reason' => $o->reason, 'status' => $o->status,
            'requested_by' => $o->requester ? ['ar' => $o->requester->displayName('ar'), 'en' => $o->requester->displayName('en')] : null, 'warnings' => [],
            'created_at' => $o->created_at?->toIso8601String(), 'steps' => Workflow::steps($o), 'step_id' => $cur?->id, 'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $o) : false, 'can_cancel' => false];
    }

    public static function person($e): ?array
    {
        return $e ? ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en]] : null;
    }

    public const WITH = ['employee', 'substitute', 'doc', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'];
}
