<?php

namespace App\Services\Workflow;

use App\Exceptions\ApiException;
use App\Models\ApprovalChain;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RequestStep;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * المسار الموحد للطلبات (SRS §10.1): بانتظار المدير المباشر ← بانتظار الموارد البشرية ← (المدير التنفيذي / الرئيس التنفيذي حسب النوع)
 * ← معتمد / مرفوض / ملغى. المستويات من «سلاسل الاعتماد»، وصاحب كل مستوى: المسمّى في السلسلة، أو المدير المباشر، أو أي حامل للدور.
 * - النائب: صاحب المستوى في إجازة معتمدة اليوم ← يُحوَّل الطلب لنائبه (delegate_id) ويُسجَّل التحويل.
 * - الإلغاء: من مقدّم الطلب فقط وهو «بانتظار المدير». الرفض بسبب إلزامي. لا يعتمد أحد طلبه.
 * - التذكير: طلب بلا قرار بعد reminder_hours يذكّر صاحبه ويتكرر.
 * الطلب (subject) يحمل status (pending_manager|pending_hr|pending_exec|approved|rejected|cancelled) و employee و requested_by.
 */
class Workflow
{
    /** أدوار الموارد البشرية متكافئة في مستوى «الموارد البشرية» */
    public const HR_ROLES = ['hr_director', 'hr_officer'];
    public const LEVEL = ['manager' => ['المدير المباشر', 'Direct manager'], 'hr_director' => ['الموارد البشرية', 'Human Resources'],
        'executive' => ['المدير التنفيذي', 'Executive Director'], 'ceo' => ['الرئيس التنفيذي', 'CEO']];

    /** مستويات سلسلة (من approval_chains، وإلا الافتراضي: المدير ← الموارد البشرية) */
    public static function chain(string $type): array
    {
        $levels = ApprovalChain::where('type', $type)->value('levels');
        return $levels ?: ($type === 'penalty' ? [self::level('ceo')] : (in_array($type, ['overtime', 'service', 'advance'], true) ? [self::level('hr_director')] : [self::level('manager'), self::level('hr_director')]));
    }

    public static function level(string $role, ?int $userId = null): array
    {
        [$ar, $en] = self::LEVEL[$role] ?? [$role, $role];
        return ['role_key' => $role, 'role_ar' => $ar, 'role_en' => $en, 'user_id' => $userId];
    }

    /** بدء المسار: إنشاء الخطوات وتفعيل أول مستوى قابل للتنفيذ */
    public function start(Model $subject, ?Employee $for, array $levels, User $by): Model
    {
        foreach (array_values($levels) as $i => $lv) {
            RequestStep::create(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'level' => $i + 1, 'role_key' => $lv['role_key'],
                'role_ar' => $lv['role_ar'], 'role_en' => $lv['role_en'], 'user_id' => $lv['user_id'] ?? null, 'status' => 'waiting']);
        }
        $this->advance($subject, $for);
        return $subject;
    }

    /** الخطوة الحالية (بانتظار القرار) */
    public static function current(Model $subject): ?RequestStep
    {
        return RequestStep::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->where('status', 'pending')->orderBy('level')->first();
    }

    /** هل يستطيع المستخدم اتخاذ قرار الخطوة؟ (ليس مقدّم الطلب ولا صاحب الطلب) */
    public static function canAct(User $u, RequestStep $s, Model $subject): bool
    {
        if ($s->status !== 'pending' || $u->company_id !== $s->company_id) return false;
        if ($u->id === $subject->requested_by || ($u->employee_id && $u->employee_id === $subject->employee_id)) return false;
        if ($s->user_id) return $s->user_id === $u->id;
        $u->loadMissing('roles');
        return in_array($s->role_key, self::HR_ROLES, true) ? $u->hasRole(...self::HR_ROLES) : $u->hasRole($s->role_key);
    }

    /**
     * قرار على الخطوة الحالية. $needsNote = ملاحظة إلزامية عند الاعتماد (تجاوز الرصيد، تعارض في الإدارة…).
     * $onApproved يُستدعى عند الاعتماد النهائي داخل نفس المعاملة.
     */
    public function decide(Model $subject, User $u, bool $approve, ?string $note, bool $needsNote = false, ?callable $onApproved = null, ?callable $guard = null): Model
    {
        $note = trim((string) $note) ?: null;
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (! $approve && mb_strlen((string) $note) < $min) throw ApiException::field('note', L::t("سبب الرفض إلزامي ({$min} أحرف على الأقل).", "A rejection reason is required (at least {$min} characters)."), 'reason_required');
        if ($approve && $needsNote && ! $note) throw ApiException::field('note', L::t('الاعتماد هنا يحتاج ملاحظة (تجاوز الرصيد أو تعارض في الإدارة) — تتحمل بها مسؤولية القرار.', 'Approving here needs a note (over balance or a clash in the department) — it records your responsibility.'), 'note_required');
        return DB::transaction(function () use ($subject, $u, $approve, $note, $onApproved, $guard) {
            $subject = $subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey());
            $s = self::current($subject);
            if (! $s || ! self::canAct($u, $s, $subject)) throw new ApiException(L::t('الطلب ليس بانتظار قرارك.', 'This request is not awaiting your decision.'), 'not_your_turn', 403);
            if ($approve && $guard) $guard($subject, $s);
            $s->update(['status' => $approve ? 'approved' : 'rejected', 'acted_by' => $u->id, 'note' => $note, 'decided_at' => now()]);
            $e = Employee::find($subject->employee_id);
            $name = [$this->label($subject, 'ar'), $this->label($subject, 'en')];
            if (! $approve) {
                RequestStep::where('subject_type', $s->subject_type)->where('subject_id', $s->subject_id)->where('status', 'waiting')->update(['status' => 'cancelled']);
                $subject->update(['status' => 'rejected', 'decided_at' => now()]);
                $e?->log("رفض {$name[0]} — {$s->role_ar}: {$note}", "{$name[1]} rejected — {$s->role_en}: {$note}", 'danger');
                Notify::user($this->userOf($subject), "لم تتم الموافقة على {$name[0]} ({$u->displayName('ar')}): {$note}", "Your {$name[1]} was declined ({$u->displayName('en')}): {$note}", 'requests');
            } else {
                $e?->log("اعتماد {$name[0]} — {$s->role_ar} ({$u->displayName('ar')})", "{$name[1]} approved — {$s->role_en} ({$u->displayName('en')})", 'ok');
                $this->advance($subject, $e, $onApproved);
            }
            Audit::record('request.'.($approve ? 'approved' : 'rejected'), $subject, null, ['level' => $s->level, 'role' => $s->role_key, 'note' => $note]);
            return $subject->refresh();
        });
    }

    /** إلغاء من مقدّم الطلب — فقط وهو بانتظار المستوى الأول */
    public function cancel(Model $subject, User $u): Model
    {
        $s = self::current($subject);
        if ($subject->requested_by !== $u->id && $u->employee_id !== $subject->employee_id) throw new ApiException(L::t('لا يمكنك إلغاء هذا الطلب.', 'You cannot cancel this request.'), 'forbidden', 403);
        if (! $s || $s->level !== self::firstLevel($subject)) throw new ApiException(L::t('يُلغى الطلب فقط قبل قرار المستوى الأول — بعد أي قرار قدّم طلباً جديداً.', 'A request can be cancelled only before the first decision — after that, submit a new one.'), 'cancel_locked', 409);
        RequestStep::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->whereIn('status', ['pending', 'waiting'])->update(['status' => 'cancelled']);
        $subject->update(['status' => 'cancelled', 'decided_at' => now()]);
        Employee::find($subject->employee_id)?->log('إلغاء '.$this->label($subject, 'ar').' من مقدّمه', $this->label($subject, 'en').' cancelled by the requester', 'warn');
        return $subject;
    }

    /** الطلبات بانتظار قرار المستخدم (صندوقه) */
    public static function inbox(User $u): Collection
    {
        return RequestStep::with('subject')->where('status', 'pending')->orderBy('activated_at')->get()
            ->filter(fn (RequestStep $s) => $s->subject && self::canAct($u, $s, $s->subject))->values();
    }

    /** تذكير أصحاب الخطوات المتأخرة (يُشغَّل كل ساعة) — يعيد عدد التذكيرات */
    public function remind(): int
    {
        $h = (int) Settings::policy('leave_rules')['reminder_hours'];
        $n = 0;
        RequestStep::with('subject')->where('status', 'pending')->where('activated_at', '<=', now()->subHours($h))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', now()->subHours($h)))->get()
            ->each(function (RequestStep $s) use (&$n, $h) {
                if (! $s->subject) return;
                $txt = [$this->label($s->subject, 'ar'), $this->label($s->subject, 'en')];
                $who = Employee::find($s->subject->employee_id);
                foreach ($this->actors($s, $s->subject) as $uid) Notify::user($uid, "تذكير: {$txt[0]} من {$who?->name} بانتظار قرارك منذ أكثر من {$h} ساعة", "Reminder: {$txt[1]} from {$who?->name_en} has waited more than {$h} hours for your decision", 'approvals');
                $s->update(['reminded_at' => now()]);
                $n++;
            });
        return $n;
    }

    /** تفعيل المستوى التالي (أو الاعتماد النهائي) — يتخطى المدير غير الموجود ويحوّل لنائب صاحب المستوى إن كان في إجازة */
    private function advance(Model $subject, ?Employee $e, ?callable $onApproved = null): void
    {
        $steps = RequestStep::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->where('status', 'waiting')->orderBy('level')->get();
        foreach ($steps as $s) {
            $owner = $this->resolve($s, $e, $subject);
            if ($owner === false) { $s->update(['status' => 'skipped', 'decided_at' => now(), 'note' => 'no manager']); continue; }
            $s->update(['status' => 'pending', 'activated_at' => now()] + ($owner ?? []));
            $subject->update(['status' => self::statusFor($s->role_key)]);
            $txt = [$this->label($subject, 'ar'), $this->label($subject, 'en')];
            foreach ($this->actors($s->refresh(), $subject) as $uid) Notify::user($uid, "{$txt[0]} من {$e?->name} بانتظار اعتمادك ({$s->role_ar})", "{$txt[1]} from {$e?->name_en} awaits your approval ({$s->role_en})", 'approvals');
            if ($s->level > self::firstLevel($subject)) Notify::user($this->userOf($subject), "{$txt[0]}: اعتُمد المستوى السابق — بانتظار {$s->role_ar}", "{$txt[1]}: previous level approved — awaiting {$s->role_en}", 'requests');
            return;
        }
        $subject->update(['status' => 'approved', 'decided_at' => now()]);
        if ($onApproved) $onApproved($subject);
        Notify::user($this->userOf($subject), 'تمت الموافقة النهائية على '.$this->label($subject, 'ar'), 'Your '.$this->label($subject, 'en').' is approved', 'requests');
    }

    /** صاحب المستوى: ['user_id' => …, 'delegate_for' => …] أو null (بالدور) أو false (يُتخطى) */
    private function resolve(RequestStep $s, ?Employee $e, Model $subject): array|null|false
    {
        $uid = $s->user_id;
        if ($s->role_key === 'manager') {
            $mgr = $e?->manager_id ? Employee::find($e->manager_id) : null;
            $uid = $mgr ? User::where('employee_id', $mgr->id)->value('id') : null;
            if (! $uid || $uid === $subject->requested_by) return false;
        }
        if (! $uid) return null;
        $owner = User::with('employee')->find($uid);
        if ($owner?->employee && self::onLeaveToday($owner->employee) && $owner->employee->delegate_id) {
            $d = User::where('employee_id', $owner->employee->delegate_id)->value('id');
            if ($d) return ['user_id' => $d, 'delegate_for' => $uid];
        }
        return ['user_id' => $uid];
    }

    public static function onLeaveToday(Employee $e): bool
    {
        $t = now()->toDateString();
        return LeaveRequest::where('employee_id', $e->id)->where('status', 'approved')->whereNotIn('type', ['hourly', 'justify'])->where('from', '<=', $t)->where('to', '>=', $t)->exists();
    }

    /** المستخدمون الذين يُشعَرون بخطوة */
    private function actors(RequestStep $s, Model $subject): array
    {
        if ($s->user_id) return [$s->user_id];
        $roles = in_array($s->role_key, self::HR_ROLES, true) ? self::HR_ROLES : [$s->role_key];
        return User::whereHas('roles', fn ($q) => $q->whereIn('key', $roles))->where('company_id', $s->company_id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $subject->employee_id))->pluck('id')->all();
    }

    public static function statusFor(string $role): string
    {
        return $role === 'manager' ? 'pending_manager' : (in_array($role, self::HR_ROLES, true) ? 'pending_hr' : 'pending_exec');
    }

    private static function firstLevel(Model $subject): int
    {
        return (int) RequestStep::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->whereNotIn('status', ['skipped'])->min('level');
    }

    private function userOf(Model $subject): ?int
    {
        return User::where('employee_id', $subject->employee_id)->value('id') ?? $subject->requested_by;
    }

    /** اسم الطلب للإشعارات والسجل */
    public function label(Model $subject, string $lang): string
    {
        return method_exists($subject, 'workflowLabel') ? $subject->workflowLabel($lang) : class_basename($subject);
    }

    /** خطوات طلب للعرض */
    public static function steps(Model $subject): array
    {
        $rows = $subject->relationLoaded('steps') ? $subject->steps : RequestStep::with(['actor.employee', 'user.employee', 'delegator.employee'])->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->orderBy('level')->get();
        $rows->loadMissing(['actor.employee', 'user.employee', 'delegator.employee']);
        return $rows->map(fn (RequestStep $s) => ['id' => $s->id, 'level' => $s->level, 'role' => ['ar' => $s->role_ar, 'en' => $s->role_en], 'status' => $s->status,
            'by' => $s->actor ? ['ar' => $s->actor->displayName('ar'), 'en' => $s->actor->displayName('en')] : null,
            'owner' => $s->user ? ['ar' => $s->user->displayName('ar'), 'en' => $s->user->displayName('en')] : null,
            'delegate_for' => $s->delegator ? ['ar' => $s->delegator->displayName('ar'), 'en' => $s->delegator->displayName('en')] : null,
            'note' => $s->note === 'no manager' ? null : $s->note, 'at' => ($s->decided_at ?? $s->activated_at)?->toIso8601String(),
            'overdue' => $s->status === 'pending' && $s->activated_at && $s->activated_at->lte(now()->subHours((int) Settings::policy('leave_rules')['reminder_hours']))])->all();
    }

    /** طلبات قديمة بمسار jsonb (بيانات الديمو) ← خطوات */
    public static function backfill(LeaveRequest $l): void
    {
        if (! $l->chain || RequestStep::where('subject_type', $l->getMorphClass())->where('subject_id', $l->id)->exists()) return;
        $pendingSeen = false;
        foreach ($l->chain as $i => $c) {
            $role = $c['role_key'] ?? ($i === 0 ? 'manager' : 'hr_director');
            $decision = $c['decision'] ?? null;
            $status = $decision ? ($decision === 'approved' ? 'approved' : 'rejected') : (str_starts_with($l->status, 'pending') && ! $pendingSeen ? 'pending' : (in_array($l->status, ['rejected', 'cancelled'], true) ? 'cancelled' : 'waiting'));
            if ($status === 'pending') $pendingSeen = true;
            $actor = isset($c['by']) ? User::whereHas('employee', fn ($q) => $q->where('name', $c['by']))->value('id') : null;
            $mgr = $role === 'manager' ? User::where('employee_id', Employee::whereKey($l->employee_id)->value('manager_id'))->value('id') : null;
            RequestStep::create(['subject_type' => $l->getMorphClass(), 'subject_id' => $l->id, 'level' => $i + 1, 'role_key' => $role, 'user_id' => $mgr,
                'role_ar' => $c['role_ar'] ?? self::LEVEL[$role][0], 'role_en' => $c['role_en'] ?? self::LEVEL[$role][1], 'status' => $status, 'acted_by' => $actor,
                'activated_at' => $status === 'pending' ? $l->created_at : null, 'decided_at' => isset($c['at']) ? $c['at'] : null]);
        }
    }
}
