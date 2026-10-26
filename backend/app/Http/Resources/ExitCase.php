<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\ExitNotice;
use App\Models\Resignation;
use App\Models\User;
use App\Services\Exit\ExitFlow;
use App\Services\Workflow\Workflow;
use App\Support\Perm;
use App\Support\Settings;

/**
 * طلب خروج للعرض. ما يراه كلٌّ: الموارد البشرية (نطاق الكل) كل شيء؛ المدير لفريقه بلا الاستبيان والمقابلات والمخالصة؛
 * الموظف طلبه بلا مقابلة HR ومقابلة الخروج.
 */
class ExitCase
{
    public const WITH = ['employee.department', 'employee.manager', 'requester.employee', 'successor.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'];

    public static function card(Resignation $r, ?User $viewer = null): array
    {
        $lists = ExitFlow::lists();
        $label = fn (string $list, ?string $k) => ($x = collect($lists[$list])->firstWhere('key', $k)) ? ['ar' => $x['ar'], 'en' => $x['en']] : null;
        $e = $r->employee;
        $cur = Workflow::current($r);
        return ['id' => $r->id, 'ref_no' => $r->ref_no, 'kind' => $r->kind, 'status' => $r->status,
            'employee' => $e ? ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'gender' => $e->gender,
                'dept' => $e->department ? ['ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null, 'hire_date' => $e->hire_date?->toDateString()] : null,
            'last_day' => $r->last_day->toDateString(), 'notice_until' => $r->notice_until?->toDateString(), 'notice_waived' => $r->notice_waived,
            'notice_short' => $r->kind === 'resignation' && ! $r->notice_waived && $r->notice_until && $r->last_day->lt($r->notice_until),
            'reasons' => $r->kind === 'termination'
                ? array_values(array_filter([$label('causes', $r->termination['cause'] ?? $r->reason_key), ...array_map(fn ($k) => $label('dismissal_reasons', $k), $r->reasons ?? [])]))
                : array_values(array_filter(array_map(fn ($k) => $label('reasons', $k), $r->reasons ?: [$r->reason_key]))),
            'withdrawal' => $r->withdrawal, 'created_at' => $r->created_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(), 'closed_at' => $r->closed_at?->toIso8601String(),
            'step_id' => $cur?->id, 'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $r) : false,
            'overdue' => in_array($r->status, Resignation::PENDING, true) && $r->created_at && $r->created_at->lte(now()->subDays((int) Settings::policy('exit')['decision_days']))];
    }

    public static function full(Resignation $r, User $viewer): array
    {
        $hr = Perm::scope($viewer, 'employees') === 'all' && $viewer->employee_id !== $r->employee_id;
        $self = $viewer->employee_id === $r->employee_id;
        $e = $r->employee;
        $isManager = $viewer->employee_id && $e?->manager_id === $viewer->employee_id;
        $h = $r->handover ?? [];
        $receiver = ! empty($h['receiver_id']) ? Employee::find($h['receiver_id']) : null;
        $out = self::card($r, $viewer) + [
            'text' => $r->text, 'survey' => $hr || $self ? $r->survey : null, 'hr_interview' => $hr ? $r->hr_interview : null, 'termination' => $r->termination,
            'rehire' => $hr ? $r->rehire : null, 'rehire_reason' => $hr ? $r->rehire_reason : null, 'waive_note' => $r->waive_note,
            'requested_by' => $r->requester ? ['ar' => $r->requester->displayName('ar'), 'en' => $r->requester->displayName('en')] : null,
            'manager' => $e?->manager ? ['ar' => $e->manager->name, 'en' => $e->manager->name_en] : null,
            'steps' => Workflow::steps($r),
            'handover' => $h ? ['tasks' => $h['tasks'] ?? [], 'receiver' => $receiver ? ['id' => $receiver->id, 'ar' => $receiver->name, 'en' => $receiver->name_en] : null,
                'employee' => $h['employee'] ?? null, 'receiver_signed' => $h['receiver'] ?? null, 'manager' => $h['manager'] ?? null, 'hr' => $h['hr'] ?? null, 'done' => ExitFlow::handoverDone($r)] : null,
            'clearance' => in_array($r->status, ['clearance', 'closed'], true) ? array_map(fn ($s) => $s + ['can_sign' => ExitFlow::canSign($viewer, $s['role'], $r)], ExitFlow::clearance($r)) : null,
            'clearance_no' => $r->clearance_no, 'clearance_issued_at' => $r->clearance_issued_at?->toIso8601String(),
            'service_cert_no' => $r->service_cert_no, 'service_cert_at' => $r->service_cert_at?->toIso8601String(),
            'exit' => $hr ? $r->exit : null, 'exit_done' => ! empty($r->exit),
            'followups' => $hr ? $r->followups : null,
            'successor' => $r->successor ? ['id' => $r->successor->id, 'ar' => $r->successor->displayName('ar'), 'en' => $r->successor->displayName('en')] : null,
            'needs_successor' => in_array($r->status, ['clearance', 'closed'], true) ? ExitFlow::needsSuccessor($r) : false, 'account_moved_at' => $r->account_moved_at?->toIso8601String(),
            'notices' => $hr ? ExitNotice::where('resignation_id', $r->id)->get()->map(fn ($n) => ['id' => $n->id, 'to' => $n->addressee, 'consent' => $n->consent, 'status' => $n->status, 'sent_at' => $n->sent_at?->toIso8601String()])->values() : [],
        ];
        $money = ($hr && Perm::can($viewer, 'payroll')) || $self;
        $money = $money && in_array($r->status, ['clearance', 'closed'], true);
        $open = $self ? \App\Services\Security\SalaryVault::open($viewer) : \App\Services\Security\SalaryVault::sees($viewer, $r->employee);
        $out['settlement'] = $money && $open ? ($r->status === 'closed' ? $r->settlement : ExitFlow::settlement($r)) : null;
        $out['settlement_masked'] = $money && ! $open;
        $out['can'] = ['interview' => $hr && $r->kind === 'resignation' && in_array($r->status, Resignation::PENDING, true),
            'waive' => ($hr || $isManager) && $r->kind === 'resignation' && $r->isOpen(),
            'withdrawal' => $hr && ($r->withdrawal['status'] ?? null) === 'pending' && $r->isOpen(),
            'handover' => ($hr || $self) && $r->status === 'clearance',
            'exit' => $hr && in_array($r->status, ['clearance', 'closed'], true),
            'close' => $hr && $r->status === 'clearance',
            'successor' => Perm::can($viewer, 'users', 'edit') && in_array($r->status, ['clearance', 'closed'], true),
            'certificate' => $hr && in_array($r->status, ['clearance', 'closed'], true),
            'print' => $hr || $self];
        return $out;
    }
}
