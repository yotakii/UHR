<?php

namespace App\Services\Work;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\User;
use App\Models\WorkTask;
use App\Models\WorkTaskComment;
use App\Services\People\Directory;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * إدارة المهام (ملاحظتا 61/62): المدير يكلّف فريقه (صلاحية «المهام» بنطاقها)، وكل موظف يضيف مهامه الخاصة.
 * المسار: جديدة ← قيد التنفيذ ← بانتظار الاعتماد (يرسلها المكلَّف) ← منجزة (يعتمدها المكلِّف) أو تُعاد للتنفيذ؛
 * المهمة الذاتية تُنجز مباشرة. عند الإنجاز تُضاف نقاط حسب الأولوية (ونصفها عند التأخر — من الإعدادات).
 */
class Tasks
{
    /** من يرى/يدير المهمة: منشئها، أو صاحب صلاحية التعديل على المكلَّف ضمن نطاقه */
    public static function canManage(User $u, WorkTask $t): bool
    {
        if ($t->created_by === $u->id) return true;
        return Perm::can($u, 'tasks', 'edit') && $t->employee_id !== $u->employee_id && Directory::scoped($u, 'tasks')->whereKey($t->employee_id)->exists();
    }

    public static function canSee(User $u, WorkTask $t): bool
    {
        return $t->employee_id === $u->employee_id || $t->created_by === $u->id || (Perm::can($u, 'tasks') && Directory::scoped($u, 'tasks')->whereKey($t->employee_id)->exists());
    }

    public function save(?WorkTask $t, array $in, User $by): WorkTask
    {
        if ($t && in_array($t->status, ['done', 'cancelled'], true)) throw new ApiException(L::t('المهمة مغلقة.', 'The task is closed.'), 'task_closed', 409);
        $d = Validator::make($in, [
            'title' => ['required', 'string', 'min:3', 'max:190'], 'description' => ['nullable', 'string', 'max:5000'],
            'employee_id' => [$t ? 'nullable' : 'required', 'integer'], 'priority' => ['required', Rule::in(WorkTask::PRIORITIES)],
            'due_on' => ['nullable', 'date'], 'kpi_id' => ['nullable', 'integer'],
        ], [], ['title' => L::t('عنوان المهمة', 'Task title'), 'employee_id' => L::t('المكلَّف', 'Assignee'), 'due_on' => L::t('الموعد', 'Due date')])->validate();
        $emp = $t?->employee_id ?? (int) $d['employee_id'];
        if (! $t) {
            $self = $emp === $by->employee_id;
            if (! $self && ! (Perm::can($by, 'tasks', 'create') && Directory::scoped($by, 'tasks')->whereKey($emp)->exists())) {
                throw ApiException::field('employee_id', L::t('الموظف خارج نطاقك.', 'This employee is outside your scope.'), 'out_of_scope');
            }
            if (! Employee::current()->whereKey($emp)->exists()) throw ApiException::field('employee_id', L::t('الموظف غير موجود.', 'Unknown employee.'), 'unknown');
        }
        if (! empty($d['kpi_id']) && ! Kpi::whereKey($d['kpi_id'])->exists()) throw ApiException::field('kpi_id', L::t('المؤشر غير موجود.', 'Unknown KPI.'), 'unknown');
        $row = ['title' => trim($d['title']), 'description' => trim((string) ($d['description'] ?? '')) ?: null, 'priority' => $d['priority'], 'due_on' => $d['due_on'] ?? null, 'kpi_id' => $d['kpi_id'] ?? null];
        if ($t) {
            if ($t->due_on?->toDateString() !== ($row['due_on'] ? substr($row['due_on'], 0, 10) : null)) $row['alerted_at'] = null;
            $t->update($row);
            return $t;
        }
        $t = WorkTask::create($row + ['employee_id' => $emp, 'created_by' => $by->id, 'status' => 'todo']);
        if ($emp !== $by->employee_id && ($uid = User::where('employee_id', $emp)->value('id'))) {
            Notify::user($uid, "مهمة جديدة من {$by->displayName('ar')}: «{$t->title}»".($t->due_on ? " — قبل {$t->due_on->toDateString()}" : ''), "New task from {$by->displayName('en')}: «{$t->title}»", 'tasks');
        }
        return $t;
    }

    /** تحديث المكلَّف: البدء، نسبة التقدّم، والإرسال للاعتماد — أو الإنجاز مباشرة في المهمة الذاتية */
    public function progress(WorkTask $t, User $u, array $in): WorkTask
    {
        if ($t->employee_id !== $u->employee_id) throw new ApiException(L::t('يحدّث المهمة المكلَّف بها فقط.', 'Only the assignee updates the task.'), 'forbidden', 403);
        if (in_array($t->status, ['done', 'cancelled'], true)) throw new ApiException(L::t('المهمة مغلقة.', 'The task is closed.'), 'task_closed', 409);
        $d = Validator::make($in, ['status' => ['required', Rule::in(['todo', 'in_progress', 'review', 'done'])], 'progress' => ['nullable', 'integer', 'between:0,100'], 'note' => ['nullable', 'string', 'max:2000']])->validate();
        $self = $t->created_by === $u->id;
        $status = $d['status'];
        if ($status === 'done' && ! $self) $status = 'review';           // مهمة من المدير: تُرسل للاعتماد
        if ($status === 'review' && $self) $status = 'done';
        $upd = ['status' => $status, 'progress' => in_array($status, ['review', 'done'], true) ? 100 : (int) ($d['progress'] ?? $t->progress)];
        if ($status === 'in_progress' && ! $t->started_at) $upd['started_at'] = now();
        if ($status === 'review') $upd['submitted_at'] = now();
        $t->update($upd);
        if (! empty($d['note'])) WorkTaskComment::create(['work_task_id' => $t->id, 'user_id' => $u->id, 'body' => trim($d['note'])]);
        if ($status === 'review' && $t->created_by) Notify::user($t->created_by, "أنجز {$u->displayName('ar')} المهمة «{$t->title}» — بانتظار اعتمادك", "{$u->displayName('en')} completed «{$t->title}» — awaiting your approval", 'tasks');
        if ($status === 'done') $this->complete($t, $u);
        return $t->refresh();
    }

    /** المكلِّف: اعتماد الإنجاز أو إعادة المهمة للتنفيذ بملاحظة */
    public function decide(WorkTask $t, User $u, bool $approve, ?string $note): WorkTask
    {
        if (! self::canManage($u, $t)) throw new ApiException(L::t('لا تملك اعتماد هذه المهمة.', 'You cannot approve this task.'), 'forbidden', 403);
        if ($t->status !== 'review') throw new ApiException(L::t('المهمة ليست بانتظار الاعتماد.', 'The task is not awaiting approval.'), 'task_state', 409);
        if (! $approve && mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الإعادة (5 أحرف على الأقل).', 'Write why it is returned (at least 5 characters).'), 'reason_required');
        if ($note) WorkTaskComment::create(['work_task_id' => $t->id, 'user_id' => $u->id, 'body' => trim($note)]);
        $uid = User::where('employee_id', $t->employee_id)->value('id');
        if (! $approve) {
            $t->update(['status' => 'in_progress', 'progress' => min($t->progress, 90)]);
            if ($uid) Notify::user($uid, "أُعيدت المهمة «{$t->title}» للتنفيذ: ".trim($note), "Task «{$t->title}» returned: ".trim($note), 'tasks');
            return $t->refresh();
        }
        $this->complete($t, $u);
        return $t->refresh();
    }

    public function cancel(WorkTask $t, User $u, string $note): WorkTask
    {
        if (! self::canManage($u, $t)) throw new ApiException(L::t('لا تملك إلغاء هذه المهمة.', 'You cannot cancel this task.'), 'forbidden', 403);
        if (in_array($t->status, ['done', 'cancelled'], true)) throw new ApiException(L::t('المهمة مغلقة.', 'The task is closed.'), 'task_closed', 409);
        if (mb_strlen(trim($note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الإلغاء (5 أحرف على الأقل).', 'Write why it is cancelled (at least 5 characters).'), 'reason_required');
        $t->update(['status' => 'cancelled', 'close_note' => trim($note), 'completed_at' => now()]);
        if ($t->employee_id !== $u->employee_id && ($uid = User::where('employee_id', $t->employee_id)->value('id'))) Notify::user($uid, "أُلغيت المهمة «{$t->title}»", "Task «{$t->title}» was cancelled", 'tasks');
        return $t;
    }

    public function comment(WorkTask $t, User $u, string $body): WorkTaskComment
    {
        if (! self::canSee($u, $t)) throw new ApiException(L::t('لا تملك هذه المهمة.', 'Not your task.'), 'forbidden', 403);
        $c = WorkTaskComment::create(['work_task_id' => $t->id, 'user_id' => $u->id, 'body' => trim($body)]);
        $to = array_filter([$t->created_by, User::where('employee_id', $t->employee_id)->value('id')], fn ($x) => $x && $x !== $u->id);
        foreach (array_unique($to) as $id) Notify::user($id, "تعليق على المهمة «{$t->title}» من {$u->displayName('ar')}", "Comment on «{$t->title}» from {$u->displayName('en')}", 'tasks');
        return $c;
    }

    private function complete(WorkTask $t, User $by): void
    {
        $t->loadMissing('employee');
        $late = $t->due_on && now()->toDateString() > $t->due_on->toDateString();
        $t->update(['status' => 'done', 'progress' => 100, 'completed_at' => now(), 'approved_by' => $by->id]);
        $pts = Points::award($t->employee, Points::forTask($t->priority, $late), 'task', $t->id, "إنجاز مهمة: {$t->title}", "Task done: {$t->title}", $by->id);
        if ($pts) $t->update(['points' => $pts]);
        if ($by->employee_id !== $t->employee_id && ($uid = User::where('employee_id', $t->employee_id)->value('id'))) Notify::user($uid, "اعتُمد إنجاز المهمة «{$t->title}»", "Task «{$t->title}» approved as done", 'tasks');
    }

    /** يومياً: مهمة تجاوزت موعدها ← تنبيه المكلَّف والمكلِّف مرة واحدة */
    public function sweep(): int
    {
        $n = 0;
        foreach (WorkTask::with('employee')->whereIn('status', ['todo', 'in_progress'])->whereNull('alerted_at')->whereNotNull('due_on')->where('due_on', '<', now()->toDateString())->get() as $t) {
            $uids = array_unique(array_filter([User::where('employee_id', $t->employee_id)->value('id'), $t->created_by]));
            foreach ($uids as $id) Notify::user($id, "مهمة متأخرة: «{$t->title}» ({$t->employee?->name}) — كان موعدها {$t->due_on->toDateString()}", "Overdue task «{$t->title}» ({$t->employee?->name_en}) — due {$t->due_on->toDateString()}", 'tasks');
            $t->update(['alerted_at' => now()]);
            $n++;
        }
        return $n;
    }

    public static function row(WorkTask $t, User $u, bool $full = false): array
    {
        $e = $t->employee;
        $today = now()->toDateString();
        $manage = self::canManage($u, $t);
        return ['id' => $t->id, 'title' => $t->title, 'description' => $t->description, 'priority' => $t->priority, 'status' => $t->status, 'progress' => $t->progress,
            'due_on' => $t->due_on?->toDateString(), 'overdue' => $t->due_on && in_array($t->status, ['todo', 'in_progress'], true) && $t->due_on->toDateString() < $today,
            'employee' => $e ? ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en]] : null,
            'by' => $t->creator ? ['ar' => $t->creator->displayName('ar'), 'en' => $t->creator->displayName('en')] : null, 'self' => $t->created_by && $t->creator?->employee_id === $t->employee_id,
            'kpi' => $t->kpi ? ['id' => $t->kpi->id, 'ar' => $t->kpi->name_ar, 'en' => $t->kpi->name_en ?: $t->kpi->name_ar] : null,
            'points' => $t->points, 'created_at' => $t->created_at?->toIso8601String(), 'completed_at' => $t->completed_at?->toIso8601String(), 'close_note' => $t->close_note,
            'comments' => $full ? $t->comments->map(fn ($c) => ['id' => $c->id, 'body' => $c->body, 'at' => $c->created_at?->toIso8601String(),
                'by' => $c->user ? ['ar' => $c->user->displayName('ar'), 'en' => $c->user->displayName('en')] : null])->values() : null,
            'comments_count' => $t->comments_count ?? null,
            'can' => ['manage' => $manage, 'progress' => $t->employee_id === $u->employee_id && ! in_array($t->status, ['done', 'cancelled'], true), 'decide' => $manage && $t->status === 'review']];
    }
}
