<?php

namespace App\Services\Security;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SalaryGrant;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * إذن الاطلاع على رواتب الآخرين (10b): يطلبه من يحتاجه (HR، المالية…) بسبب ونطاق (كل الشركة أو إدارة)،
 * فيمر بالمسار الموحد (سلسلة salary_access ← المدير العام)، ويصلح grant_hours ساعة من لحظة الموافقة ثم ينتهي وحده.
 * يسحبه المعتمِد (الأدوار المعفاة) أو مدير الإعدادات في أي وقت. التحقق بخطوتين يبقى مطلوباً فوقه.
 */
class SalaryGrants
{
    public function __construct(private Workflow $flow) {}

    public function request(User $u, array $d): SalaryGrant
    {
        if (! SalaryVault::needsGrant($u)) throw new ApiException(L::t('لا تحتاج إذناً — صلاحيتك تشمل الرواتب بعد التحقق.', 'You don’t need access — your role covers salaries after verification.'), 'grant_not_needed', 409);
        $open = SalaryGrant::where('user_id', $u->id)->where(fn ($q) => $q->where('status', 'like', 'pending%')->orWhere(fn ($q) => $q->where('status', 'approved')->where('expires_at', '>', now())))->exists();
        if ($open) throw new ApiException(L::t('لديك طلب قائم أو إذن ساري.', 'You already have an open request or an active access.'), 'grant_open', 409);
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (mb_strlen(trim((string) ($d['reason'] ?? ''))) < $min) throw ApiException::field('reason', L::t("اكتب سبب الطلب ({$min} أحرف على الأقل).", "Write the reason (at least {$min} characters)."), 'reason_required');
        $scope = ($d['scope'] ?? 'all') === 'department' ? 'department' : 'all';
        $dept = $scope === 'department' ? Department::find($d['department_id'] ?? null) : null;
        if ($scope === 'department' && ! $dept) throw ApiException::field('department_id', L::t('اختر الإدارة.', 'Choose the department.'), 'required');
        return DB::transaction(function () use ($u, $d, $scope, $dept) {
            $g = SalaryGrant::create(['user_id' => $u->id, 'employee_id' => $u->employee_id, 'scope' => $scope, 'department_id' => $dept?->id, 'reason' => trim($d['reason']),
                'hours' => (int) Settings::vault()['grant_hours'], 'requested_by' => $u->id, 'status' => 'pending_exec']);
            $this->flow->start($g, $u->employee_id ? Employee::find($u->employee_id) : null, Workflow::chain('salary_access'), $u);
            return $g->refresh();
        });
    }

    public function decide(SalaryGrant $g, User $u, bool $approve, ?string $note): SalaryGrant
    {
        return $this->flow->decide($g, $u, $approve, $note, false, function (SalaryGrant $g) {
            $g->update(['expires_at' => now()->addHours($g->hours)]);
            Audit::record('salary.access_granted', $g, null, ['user' => $g->user_id, 'scope' => $g->scope, 'department' => $g->department_id, 'until' => $g->expires_at->toIso8601String()]);
        });
    }

    public function cancel(SalaryGrant $g, User $u): SalaryGrant
    {
        return $this->flow->cancel($g, $u);
    }

    public static function canRevoke(User $u): bool
    {
        $roles = Settings::vault()['grant_exempt_roles'];
        $u->loadMissing('roles');
        return Perm::can($u, 'settings', 'edit') || ($roles && $u->hasRole(...$roles));
    }

    public function revoke(SalaryGrant $g, User $u, ?string $note): SalaryGrant
    {
        if (! self::canRevoke($u)) throw new ApiException(L::t('لا يمكنك سحب الإذن.', 'You cannot revoke this access.'), 'forbidden', 403);
        if (! $g->isActive() && ! str_starts_with($g->status, 'pending')) throw new ApiException(L::t('الإذن غير ساري.', 'This access is not active.'), 'grant_inactive', 409);
        if (str_starts_with($g->status, 'pending')) \App\Models\RequestStep::where('subject_type', $g->getMorphClass())->where('subject_id', $g->id)->whereIn('status', ['pending', 'waiting'])->update(['status' => 'cancelled']);
        $g->update(['status' => 'revoked', 'revoked_by' => $u->id, 'revoked_at' => now(), 'revoke_note' => trim((string) $note) ?: null]);
        Notify::user($g->user_id, 'سُحب إذن اطلاعك على الرواتب ('.$u->displayName('ar').')', 'Your salary access was revoked ('.$u->displayName('en').')', 'requests');
        Audit::record('salary.access_revoked', $g, null, ['note' => $g->revoke_note]);
        return $g;
    }

    public static function row(SalaryGrant $g, ?User $viewer = null): array
    {
        $cur = Workflow::current($g);
        $who = $g->user;
        return ['kind' => 'salary', 'id' => $g->id, 'type' => 'salary_access', 'type_name' => ['ar' => $g->workflowLabel('ar'), 'en' => $g->workflowLabel('en')],
            'employee' => $g->employee ? \App\Http\Resources\RequestRow::person($g->employee) : ($who ? ['id' => null, 'code' => '', 'name' => ['ar' => $who->displayName('ar'), 'en' => $who->displayName('en')], 'title' => ['ar' => '', 'en' => '']] : null),
            'user' => $who ? ['id' => $who->id, 'ar' => $who->displayName('ar'), 'en' => $who->displayName('en'), 'email' => $who->email] : null,
            'from' => $g->created_at?->toDateString(), 'to' => $g->created_at?->toDateString(), 'reason' => $g->reason, 'status' => $g->state(), 'scope' => $g->scope, 'hours' => $g->hours,
            'department' => $g->department ? ['ar' => $g->department->name_ar, 'en' => $g->department->name_en] : null,
            'expires_at' => $g->expires_at?->toIso8601String(), 'revoked_at' => $g->revoked_at?->toIso8601String(), 'revoke_note' => $g->revoke_note,
            'revoker' => $g->revoker ? ['ar' => $g->revoker->displayName('ar'), 'en' => $g->revoker->displayName('en')] : null, 'warnings' => [],
            'created_at' => $g->created_at?->toIso8601String(), 'steps' => Workflow::steps($g), 'step_id' => $cur?->id,
            'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $g) : false, 'can_cancel' => $viewer && $cur && $viewer->id === $g->user_id];
    }

    public const WITH = ['user.employee', 'employee', 'department', 'revoker.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'];
}
