<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PenaltyApproval;
use App\Models\Resignation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Leave\LeaveService;
use App\Services\Workflow\Workflow;
use App\Support\ViolationCatalog;

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

    public static function service(ServiceRequest $x, ?User $viewer = null): array
    {
        $cur = Workflow::current($x);
        return ['kind' => 'service', 'id' => $x->id, 'type' => $x->kind, 'type_name' => ['ar' => $x->workflowLabel('ar'), 'en' => $x->workflowLabel('en')],
            'employee' => self::person($x->employee), 'from' => $x->created_at?->toDateString(), 'to' => $x->created_at?->toDateString(), 'reason' => (string) $x->note, 'status' => $x->status,
            'amount' => $x->amount, 'currency' => $x->employee?->currency, 'addressee' => $x->addressee, 'notify_consent' => $x->notify_consent, 'data' => $x->data,
            'ref_no' => $x->ref_no, 'issued_at' => $x->issued_at?->toIso8601String(), 'warnings' => [],
            'requested_by' => $x->requester ? ['ar' => $x->requester->displayName('ar'), 'en' => $x->requester->displayName('en')] : null,
            'created_at' => $x->created_at?->toIso8601String(), 'steps' => Workflow::steps($x), 'step_id' => $cur?->id, 'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $x) : false,
            'can_cancel' => $viewer && $cur && ($viewer->employee_id === $x->employee_id || $viewer->id === $x->requested_by) && $cur->level === (int) $x->steps->whereNotIn('status', ['skipped'])->min('level')];
    }

    /** طلب موافقة المدير العام على جزاء (M17) */
    public static function penalty(PenaltyApproval $x, ?User $viewer = null): array
    {
        $cur = Workflow::current($x);
        $c = $x->case;
        $label = ['ar' => ViolationCatalog::label($x->penalty), 'en' => ViolationCatalog::label($x->penalty, 'en')];
        return ['kind' => 'penalty', 'id' => $x->id, 'type' => 'penalty', 'type_name' => ['ar' => $x->workflowLabel('ar'), 'en' => $x->workflowLabel('en')],
            'employee' => self::person($x->employee), 'from' => $c?->occurred_on?->toDateString(), 'to' => $c?->occurred_on?->toDateString(), 'status' => $x->status,
            'reason' => trim(($c?->type ? $c->type->title_ar : '').' — '.$label['ar'].($x->reason ? ' — '.$x->reason : ''), ' —'),
            'penalty' => $x->penalty + ['label' => $label], 'case' => $c ? ['id' => $c->id, 'ref_no' => $c->ref_no, 'occurrence' => $c->occurrence, 'description' => $c->description,
                'type' => $c->type ? ['ar' => $c->type->title_ar, 'en' => $c->type->title_en] : null] : null, 'warnings' => [],
            'requested_by' => $x->requester ? ['ar' => $x->requester->displayName('ar'), 'en' => $x->requester->displayName('en')] : null,
            'created_at' => $x->created_at?->toIso8601String(), 'steps' => Workflow::steps($x), 'step_id' => $cur?->id, 'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $x) : false, 'can_cancel' => false];
    }

    /** طلب استقالة / إنهاء عقد (M11) في صندوق الاعتماد */
    public static function exit(Resignation $x, ?User $viewer = null): array
    {
        $c = ExitCase::card($x, $viewer);
        return ['kind' => 'exit', 'id' => $x->id, 'type' => $x->kind, 'type_name' => ['ar' => $x->workflowLabel('ar'), 'en' => $x->workflowLabel('en')],
            'employee' => self::person($x->employee), 'from' => $x->created_at?->toDateString(), 'to' => $x->last_day->toDateString(), 'status' => $x->status,
            'reason' => implode('، ', array_map(fn ($r) => $r['ar'], $c['reasons'])).($x->kind === 'termination' ? ' — '.$x->text : ''), 'ref_no' => $x->ref_no, 'last_day' => $x->last_day->toDateString(),
            'notice_short' => $c['notice_short'], 'warnings' => [], 'interview_needed' => $x->kind === 'resignation' && empty($x->hr_interview),
            'requested_by' => $x->requester ? ['ar' => $x->requester->displayName('ar'), 'en' => $x->requester->displayName('en')] : null,
            'created_at' => $x->created_at?->toIso8601String(), 'steps' => Workflow::steps($x), 'step_id' => $c['step_id'], 'can_act' => $c['can_act'], 'can_cancel' => false];
    }

    public const PENALTY_WITH = ['employee', 'case.type', 'requester.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'];

    public const SERVICE_WITH = ['employee', 'requester.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'];

    public static function person($e): ?array
    {
        return $e ? ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en]] : null;
    }

    public const WITH = ['employee', 'substitute', 'doc', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'];
}
