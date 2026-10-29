<?php

namespace App\Services\Exit;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\ExitNotice;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\RequestStep;
use App\Models\Resignation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * الخروج حسب إجراء الشركة HR.01-03 (SRS §10.8) — كل المدد والمبالغ من سياسة exit، والقوائم من إعدادات offboarding:
 * - الاستقالة F-02: الموظف يقدّمها بأسبابها وتاريخ نفاذها واستبيانها ← المدير (توصية، وله الإعفاء من الإخطار) ← HR (مقابلة وتأكيد الأسباب) ← المدير العام.
 * - إنهاء العقد F-03: من HR أو المدير المباشر، بنوعه وأسبابه وأهلية إعادة التعيين ← بقية السلسلة.
 * - بعد القرار: clearance (F-05 تسليم المهام، F-07 تسليم العهدة بجهاته ← F-04 براءة الذمة، مقابلة الخروج، المخالصة F-33) ← الإغلاق.
 * - الرجوع عن الاستقالة: فوري قبل أي قرار، وبعده بموافقة HR. الرفض يعيد الموظف لحالته السابقة.
 */
class ExitFlow
{
    public function __construct(private Workflow $flow) {}

    public static function lists(): array { return Settings::offboarding(); }
    private static function keys(string $list): array { return array_column(self::lists()[$list], 'key'); }

    // ── التقديم ──

    public function submit(Employee $e, array $in, User $by): Resignation
    {
        $d = Validator::make($in, ['last_day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'reasons' => ['required', 'array', 'min:1'], 'reasons.*' => ['in:'.implode(',', self::keys('reasons'))],
            'text' => ['required', 'string', 'min:10', 'max:4000'], 'survey' => ['nullable', 'array'], 'survey.*' => ['nullable', 'string', 'max:1000']],
            ['last_day.after_or_equal' => L::t('تاريخ النفاذ اليوم أو بعده.', 'The effective date is today or later.')],
            ['last_day' => L::t('آخر يوم عمل', 'Last working day'), 'reasons' => L::t('أسباب الاستقالة', 'Reasons'), 'text' => L::t('نص الطلب', 'Request text')])->validate();
        $this->ensureNoOpen($e);
        return DB::transaction(function () use ($e, $d, $by) {
            $p = Settings::policy('exit');
            $survey = array_intersect_key(array_filter($d['survey'] ?? [], fn ($v) => trim((string) $v) !== ''), array_flip(self::keys('questions')));
            $r = Resignation::create(['employee_id' => $e->id, 'kind' => 'resignation', 'ref_no' => $this->ref('RSG'), 'last_day' => $d['last_day'], 'reason_key' => $d['reasons'][0],
                'reasons' => array_values(array_unique($d['reasons'])), 'text' => trim($d['text']), 'survey' => $survey ?: null, 'status' => 'pending_manager', 'prev_status' => $e->status,
                'notice_until' => now()->addDays((int) $p['notice_days'])->toDateString(), 'clearance' => [], 'requested_by' => $by->id]);
            $e->update(['status' => 'resigning']);
            $e->log("تقديم طلب استقالة {$r->ref_no} — آخر يوم {$r->last_day->toDateString()}", "Resignation {$r->ref_no} submitted — last day {$r->last_day->toDateString()}", 'warn', 'F-02');
            $this->flow->start($r, $e, Workflow::chain('resignation'), $by);
            return $r->refresh();
        });
    }

    /** إنهاء عقد F-03 من HR (نطاق الكل) أو المدير المباشر */
    public function terminate(User $by, Employee $e, array $in): Resignation
    {
        $isManager = $by->employee_id && $e->manager_id === $by->employee_id;
        $isHr = Perm::can($by, 'employees', 'edit') && Perm::scope($by, 'employees') === 'all';
        if (! $isManager && ! $isHr) throw new ApiException(L::t('إنهاء العقد من الموارد البشرية أو المدير المباشر.', 'A termination is raised by HR or the direct manager.'), 'forbidden', 403);
        if ($e->id === $by->employee_id) throw new ApiException(L::t('لا يمكنك إنهاء عقدك.', 'You cannot terminate yourself.'), 'forbidden', 403);
        $d = Validator::make($in, ['cause' => ['required', 'in:'.implode(',', self::keys('causes'))], 'reasons' => ['nullable', 'array'], 'reasons.*' => ['in:'.implode(',', self::keys('dismissal_reasons'))],
            'other' => ['nullable', 'string', 'max:1000'], 'last_day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'rehire' => ['required', 'boolean'],
            'rehire_reason' => ['nullable', 'string', 'max:1000'], 'notes' => ['required', 'string', 'min:10', 'max:4000']],
            ['last_day.after_or_equal' => L::t('آخر يوم عمل اليوم أو بعده.', 'The last day is today or later.')],
            ['cause' => L::t('نوع الإنهاء', 'Termination type'), 'notes' => L::t('الملاحظات', 'Notes'), 'last_day' => L::t('آخر يوم عمل', 'Last working day')])->validate();
        if ($d['cause'] === 'dismissal' && empty($d['reasons'])) throw ApiException::field('reasons', L::t('اختر سبب الفصل.', 'Choose the dismissal reason.'), 'reasons_required');
        if (in_array('other', $d['reasons'] ?? [], true) && mb_strlen(trim((string) ($d['other'] ?? ''))) < 5) throw ApiException::field('other', L::t('اذكر الأسباب الأخرى.', 'Describe the other reasons.'), 'other_required');
        if (! $d['rehire'] && mb_strlen(trim((string) ($d['rehire_reason'] ?? ''))) < 5) throw ApiException::field('rehire_reason', L::t('اذكر سبب عدم الأهلية لإعادة التعيين.', 'Give the reason the employee is not eligible for rehire.'), 'rehire_reason_required');
        $this->ensureNoOpen($e);
        return DB::transaction(function () use ($by, $e, $d, $isHr) {
            $r = Resignation::create(['employee_id' => $e->id, 'kind' => 'termination', 'ref_no' => $this->ref('TRM'), 'last_day' => $d['last_day'], 'reason_key' => $d['cause'],
                'reasons' => $d['reasons'] ?? [], 'text' => trim($d['notes']), 'termination' => ['cause' => $d['cause'], 'reasons' => $d['reasons'] ?? [], 'other' => trim((string) ($d['other'] ?? '')) ?: null],
                'rehire' => (bool) $d['rehire'], 'rehire_reason' => trim((string) ($d['rehire_reason'] ?? '')) ?: null, 'status' => 'pending_manager', 'prev_status' => $e->status,
                'clearance' => [], 'requested_by' => $by->id]);
            $e->log("إنهاء عقد {$r->ref_no} قيد الاعتماد — آخر يوم {$r->last_day->toDateString()}", "Contract termination {$r->ref_no} pending — last day {$r->last_day->toDateString()}", 'danger', 'F-03');
            // HR الذي يرفع الإنهاء يكتب ملاحظاته في النموذج فلا يعتمده مرة ثانية
            $levels = array_values(array_filter(Workflow::chain('termination'), fn ($lv) => ! ($isHr && in_array($lv['role_key'], Workflow::HR_ROLES, true))));
            $this->flow->start($r, $e, $levels, $by);
            return $r->refresh();
        });
    }

    private function ensureNoOpen(Employee $e): void
    {
        if ($e->status === 'terminated') throw new ApiException(L::t('انتهت خدمة هذا الموظف.', 'This employee has already left.'), 'terminated', 409);
        if (Resignation::where('employee_id', $e->id)->whereIn('status', Resignation::OPEN)->exists()) throw new ApiException(L::t('يوجد طلب خروج مفتوح لهذا الموظف.', 'An exit request is already open for this employee.'), 'exit_open', 409);
    }

    private function ref(string $p): string
    {
        $y = now()->year;
        return "{$p}-{$y}-".str_pad((string) Counters::next(strtolower($p)."-{$y}"), 4, '0', STR_PAD_LEFT);
    }

    // ── الاعتماد ──

    /** القرار من صاحب المستوى. مستوى HR في الاستقالة يحتاج مقابلة الاستقالة أولاً */
    public function decide(Resignation $r, User $u, bool $approve, ?string $note): Resignation
    {
        $guard = function (Resignation $x, RequestStep $s) {
            if ($x->kind === 'resignation' && in_array($s->role_key, Workflow::HR_ROLES, true) && empty($x->hr_interview)) {
                throw new ApiException(L::t('سجّل مقابلة الاستقالة وتأكيد الأسباب قبل الاعتماد.', 'Record the resignation interview and confirm the reasons before approving.'), 'interview_required', 409);
            }
        };
        $r = $this->flow->decide($r, $u, $approve, $note, false, fn (Resignation $x) => $this->approved($x, $u), $guard);
        if (! $approve) $this->restore($r, 'رُفض '.$r->workflowLabel('ar'), $r->workflowLabel('en').' declined');
        return $r;
    }

    /** الاعتماد النهائي: بدء الإجراءات (clearance) */
    private function approved(Resignation $r, User $u): void
    {
        $e = Employee::findOrFail($r->employee_id);
        $r->update(['status' => 'clearance', 'decided_at' => now(), 'clearance' => $this->blankClearance(), 'handover' => $r->handover ?? ['tasks' => []]]);
        $e->update(['status' => 'resigning']);
        $e->log("اعتماد {$r->workflowLabel('ar')} {$r->ref_no} — بدء إجراءات الخروج، آخر يوم {$r->last_day->toDateString()}", "{$r->workflowLabel('en')} {$r->ref_no} approved — exit procedures started, last day {$r->last_day->toDateString()}", 'warn');
        // إشعار تقانة المعلومات بآخر يوم عمل (لإغلاق الحسابات والبريد)
        foreach (User::whereHas('roles', fn ($q) => $q->where('key', 'admin'))->where('active', true)->pluck('id') as $id) {
            Notify::user($id, "آخر يوم عمل لـ {$e->name} هو {$r->last_day->toDateString()} — أغلق الحسابات والبريد", "{$e->name_en}'s last working day is {$r->last_day->toDateString()} — close the accounts and mailbox", 'offboarding:'.$r->id);
        }
        Notify::hr("بدء إجراءات خروج {$e->name}: تسليم المهام والعهدة والمخالصة ({$r->ref_no})", "Exit procedures started for {$e->name_en} ({$r->ref_no})", 'offboarding:'.$r->id);
        $this->prepareNotices($r, $e);
    }

    /** الرفض أو الرجوع: يعود الموظف لحالته قبل الطلب */
    private function restore(Resignation $r, string $ar, string $en): void
    {
        $e = Employee::find($r->employee_id);
        if ($e && $e->status === 'resigning') $e->update(['status' => $r->prev_status && $r->prev_status !== 'resigning' ? $r->prev_status : 'active']);
        $e?->log("{$ar} ({$r->ref_no}) — يعود الموظف لحالته", "{$en} ({$r->ref_no}) — the employee is back to their status", 'info');
    }

    /** مقابلة الاستقالة (HR): تأكيد الأسباب والتوصية */
    public function interview(Resignation $r, User $u, array $in): Resignation
    {
        if (! in_array($r->status, Resignation::PENDING, true)) throw new ApiException(L::t('الطلب ليس قيد الاعتماد.', 'The request is not pending.'), 'not_pending', 409);
        $d = Validator::make($in, ['reasons' => ['required', 'array', 'min:1'], 'reasons.*' => ['in:'.implode(',', self::keys('reasons'))], 'notes' => ['required', 'string', 'min:5', 'max:4000'],
            'recommendation' => ['required', 'in:accept,retain']], [], ['notes' => L::t('ملاحظات المقابلة', 'Interview notes')])->validate();
        $r->update(['hr_interview' => ['reasons' => $d['reasons'], 'notes' => trim($d['notes']), 'recommendation' => $d['recommendation'], 'at' => now()->toIso8601String(), 'by' => $u->displayName('ar'), 'by_en' => $u->displayName('en')]]);
        Employee::find($r->employee_id)?->log("مقابلة الاستقالة {$r->ref_no} ({$u->displayName('ar')})", "Resignation interview {$r->ref_no} ({$u->displayName('en')})", 'info', 'F-02');
        return $r;
    }

    /** الإعفاء من فترة الإخطار (المدير المباشر أو HR) — ويمكن تقديم آخر يوم */
    public function waive(Resignation $r, User $u, array $in): Resignation
    {
        $e = Employee::findOrFail($r->employee_id);
        $isManager = $u->employee_id && $e->manager_id === $u->employee_id;
        if (! $isManager && Perm::scope($u, 'employees') !== 'all') throw new ApiException(L::t('الإعفاء من الإخطار للمدير المباشر أو الموارد البشرية.', 'Only the direct manager or HR can waive the notice.'), 'forbidden', 403);
        if (! $r->isOpen() || $r->kind !== 'resignation') throw new ApiException(L::t('لا يمكن تعديل الإخطار الآن.', 'The notice cannot be changed now.'), 'closed', 409);
        $d = Validator::make($in, ['note' => ['required', 'string', 'min:5', 'max:1000'], 'last_day' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today']])->validate();
        $r->update(['notice_waived' => true, 'waive_note' => trim($d['note'])] + (! empty($d['last_day']) ? ['last_day' => $d['last_day']] : []));
        $e->log("إعفاء من فترة الإخطار ({$u->displayName('ar')}): {$d['note']}", "Notice waived ({$u->displayName('en')}): {$d['note']}", 'info');
        Notify::user(User::where('employee_id', $e->id)->value('id'), "أُعفيت من فترة الإخطار — آخر يوم عمل {$r->last_day->toDateString()}", "Your notice period was waived — last day {$r->last_day->toDateString()}", 'resignation');
        return $r;
    }

    /** الرجوع عن الاستقالة: فوري قبل أي قرار، وبعده يُرفع لـ HR */
    public function withdraw(Resignation $r, User $u, string $text): Resignation
    {
        if ($u->employee_id !== $r->employee_id || $r->kind !== 'resignation') abort(404);
        if (! $r->isOpen()) throw new ApiException(L::t('الطلب مغلق.', 'The request is closed.'), 'closed', 409);
        $decided = RequestStep::where('subject_type', $r->getMorphClass())->where('subject_id', $r->id)->where('status', 'approved')->exists();
        if (! $decided) {
            $this->flow->cancel($r, $u);
            $r->update(['withdrawal' => ['text' => trim($text), 'at' => now()->toIso8601String(), 'status' => 'accepted']]);
            $this->restore($r, 'رجوع عن الاستقالة', 'Resignation withdrawn');
            return $r->refresh();
        }
        if (($r->withdrawal['status'] ?? null) === 'pending') throw new ApiException(L::t('طلب الرجوع قيد الدراسة.', 'Your withdrawal is under review.'), 'withdraw_pending', 409);
        $r->update(['withdrawal' => ['text' => trim($text), 'at' => now()->toIso8601String(), 'status' => 'pending']]);
        Notify::hr("طلب رجوع عن الاستقالة {$r->ref_no}", "Resignation withdrawal requested ({$r->ref_no})", 'offboarding:'.$r->id);
        return $r;
    }

    public function decideWithdrawal(Resignation $r, User $u, bool $accept, ?string $note): Resignation
    {
        if (($r->withdrawal['status'] ?? null) !== 'pending' || ! $r->isOpen()) throw new ApiException(L::t('لا يوجد طلب رجوع قيد الدراسة.', 'No pending withdrawal.'), 'no_withdrawal', 409);
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (mb_strlen(trim((string) $note)) < $min) throw ApiException::field('note', L::t("اكتب ملاحظة القرار ({$min} أحرف على الأقل) — وتحديث العقد إن تغيّرت الشروط.", "Write the decision note (at least {$min} characters) — and any contract change."), 'reason_required');
        return DB::transaction(function () use ($r, $u, $accept, $note) {
            $w = $r->withdrawal + ['status' => $accept ? 'accepted' : 'rejected', 'decided_by' => $u->displayName('ar'), 'decided_by_en' => $u->displayName('en'), 'note' => trim((string) $note), 'decided_at' => now()->toIso8601String()];
            if ($accept) {
                RequestStep::where('subject_type', $r->getMorphClass())->where('subject_id', $r->id)->whereIn('status', ['pending', 'waiting'])->update(['status' => 'cancelled']);
                $r->update(['withdrawal' => $w, 'status' => 'cancelled']);
                $this->restore($r, 'قبول الرجوع عن الاستقالة', 'Resignation withdrawal accepted');
                ExitNotice::where('resignation_id', $r->id)->where('status', 'prepared')->delete();
            } else $r->update(['withdrawal' => $w]);
            Notify::user(User::where('employee_id', $r->employee_id)->value('id'), $accept ? 'قُبل رجوعك عن الاستقالة — أهلاً بعودتك' : "لم يُقبل الرجوع عن الاستقالة: {$note}",
                $accept ? 'Your resignation withdrawal was accepted' : "Your withdrawal was not accepted: {$note}", 'resignation');
            Audit::record('exit.withdrawal_'.($accept ? 'accepted' : 'rejected'), $r, null, ['note' => $note]);
            return $r->refresh();
        });
    }

    // ── F-05 تسليم المهام ──

    public function saveHandover(Resignation $r, User $u, array $in): Resignation
    {
        $this->inClearance($r);
        if ($u->employee_id !== $r->employee_id && Perm::scope($u, 'employees') !== 'all') abort(403);
        $d = Validator::make($in, ['receiver_id' => ['required', 'integer'], 'tasks' => ['required', 'array', 'min:1', 'max:20'], 'tasks.*.task' => ['required', 'string', 'max:300'],
            'tasks.*.status' => ['required', 'in:done,in_progress,postponed'], 'tasks.*.files' => ['nullable', 'string', 'max:300'], 'tasks.*.type' => ['nullable', 'in:paper,electronic'],
            'tasks.*.remaining' => ['nullable', 'string', 'max:500'], 'tasks.*.location' => ['nullable', 'string', 'max:300']],
            [], ['receiver_id' => L::t('المستلم', 'Receiver'), 'tasks' => L::t('المهام', 'Tasks')])->validate();
        if ((int) $d['receiver_id'] === $r->employee_id || ! Employee::current()->whereKey($d['receiver_id'])->where('status', '!=', 'resigning')->exists()) throw ApiException::field('receiver_id', L::t('اختر موظفاً على رأس عمله يستلم المهام.', 'Choose a current employee to receive the tasks.'), 'invalid');
        $tasks = array_map(fn ($t) => ['task' => trim($t['task']), 'status' => $t['status'], 'files' => trim((string) ($t['files'] ?? '')) ?: null, 'type' => $t['type'] ?? null,
            'remaining' => trim((string) ($t['remaining'] ?? '')) ?: null, 'location' => trim((string) ($t['location'] ?? '')) ?: null], array_values($d['tasks']));
        // أي تعديل يُسقط التواقيع السابقة
        $r->update(['handover' => ['tasks' => $tasks, 'receiver_id' => (int) $d['receiver_id'], 'updated_at' => now()->toIso8601String()]]);
        return $r;
    }

    /** التوقيع بالترتيب: تعهد المسلِّم ← إقرار المستلم ← مصادقة المدير ← مصادقة HR */
    public function signHandover(Resignation $r, User $u, string $as): Resignation
    {
        $this->inClearance($r);
        $h = $r->handover ?? [];
        if (empty($h['tasks'])) throw new ApiException(L::t('أدخل جدول المهام أولاً.', 'Fill in the task list first.'), 'no_tasks', 409);
        $e = Employee::findOrFail($r->employee_id);
        $order = ['employee', 'receiver', 'manager', 'hr'];
        $i = array_search($as, $order, true);
        if ($i === false) abort(404);
        foreach (array_slice($order, 0, $i) as $prev) if (empty($h[$prev])) throw new ApiException(L::t('التواقيع بالترتيب: المسلِّم ثم المستلم ثم المدير ثم الموارد البشرية.', 'Signatures go in order: employee, receiver, manager, HR.'), 'out_of_order', 409);
        if (! empty($h[$as])) throw new ApiException(L::t('سبق التوقيع.', 'Already signed.'), 'already_signed', 409);
        $ok = match ($as) {
            'employee' => $u->employee_id === $r->employee_id,
            'receiver' => $u->employee_id === ($h['receiver_id'] ?? 0),
            'manager' => ($u->employee_id && $u->employee_id === $e->manager_id) || (! $e->manager_id && Perm::scope($u, 'employees') === 'all'),
            'hr' => Perm::scope($u, 'employees') === 'all' && $u->employee_id !== $r->employee_id,
        };
        if (! $ok) throw new ApiException(L::t('هذا التوقيع ليس لك.', 'This signature is not yours.'), 'forbidden', 403);
        $h[$as] = ['by' => $u->displayName('ar'), 'by_en' => $u->displayName('en'), 'at' => now()->toIso8601String()];
        $r->update(['handover' => $h]);
        if ($as === 'employee' && ($rid = User::where('employee_id', $h['receiver_id'])->value('id'))) Notify::user($rid, "استلام مهام من {$e->name} — راجع جدول المهام ووقّع الإقرار", "Task handover from {$e->name_en} — review and acknowledge", 'handover:'.$r->id);
        if ($as === 'hr') $e->log('اكتمل تسليم المهام F-05', 'Task handover complete (F-05)', 'ok', 'F-05');
        return $r;
    }

    public static function handoverDone(Resignation $r): bool { return ! empty($r->handover['hr']); }

    // ── F-07 تسليم العهدة ──

    private function blankClearance(): array
    {
        $out = [];
        foreach (self::lists()['sections'] as $s) $out[$s['key']] = ['items' => [], 'signed' => null];
        return $out;
    }

    /** الجهات وبنودها مع القيم الآلية (المهام، الأصول، الرصيد) */
    public static function clearance(Resignation $r): array
    {
        $e = $r->relationLoaded('employee') ? $r->employee : Employee::find($r->employee_id);
        $assets = \App\Models\Asset::with('category')->where('employee_id', $r->employee_id)->whereIn('status', ['assigned', 'pending_ack'])->get();
        $bal = $e ? app(\App\Services\Leave\LeaveService::class)->balance($e) : null;
        return array_map(function ($s) use ($r, $assets, $bal) {
            $saved = $r->clearance[$s['key']] ?? ['items' => [], 'signed' => null];
            $items = array_map(function ($it) use ($saved, $r, $assets, $bal) {
                $v = $saved['items'][$it['key']] ?? null;
                $auto = $it['auto'] ?? null;
                $row = ['key' => $it['key'], 'ar' => $it['ar'], 'en' => $it['en'], 'auto' => $auto, 'amount' => (bool) ($it['amount'] ?? false), 'state' => $v['state'] ?? null, 'note' => $v['note'] ?? null, 'value' => $v['value'] ?? null];
                if ($auto === 'handover') $row['state'] = self::handoverDone($r) ? 'done' : null;
                if ($auto === 'assets') {
                    $row['state'] = $assets->isEmpty() ? 'done' : null;
                    $row['pending'] = $assets->map(fn ($a) => ['id' => $a->id, 'tag' => $a->tag, 'name' => ['ar' => $a->name_ar, 'en' => $a->name_en], 'value' => (float) $a->value, 'status' => $a->status])->values();
                }
                if ($auto === 'leave') $row['balance'] = $bal ? ['entitlement' => $bal['entitlement'], 'used' => $bal['used'], 'remaining' => max(0, $bal['remaining'])] : null;
                return $row;
            }, $s['items']);
            return ['key' => $s['key'], 'ar' => $s['ar'], 'en' => $s['en'], 'role' => $s['role'], 'items' => $items, 'signed' => $saved['signed'] ?? null,
                'ready' => collect($items)->every(fn ($i) => $i['state'] !== null)];
        }, self::lists()['sections']);
    }

    public static function canSign(User $u, string $role, Resignation $r): bool
    {
        if ($u->employee_id === $r->employee_id || $u->company_id !== $r->company_id) return false;
        $u->loadMissing('roles');
        if ($u->hasRole(...Workflow::HR_ROLES)) return true;
        if ($role === 'manager') return $u->employee_id && $u->employee_id === Employee::whereKey($r->employee_id)->value('manager_id');
        return $u->hasRole($role);
    }

    /** تأشير بند: done (تم/لا يوجد)، missing (لم يُسلَّم — مع ملاحظة)، أو مبلغ للمالية */
    public function markItem(Resignation $r, User $u, string $section, string $item, array $in): Resignation
    {
        $this->inClearance($r);
        $s = collect(self::lists()['sections'])->firstWhere('key', $section) ?? abort(404);
        $it = collect($s['items'])->firstWhere('key', $item) ?? abort(404);
        if (! self::canSign($u, $s['role'], $r)) throw new ApiException(L::t('هذه الجهة ليست من صلاحيتك.', 'This section is not yours.'), 'forbidden', 403);
        if (! empty($r->clearance[$section]['signed'])) throw new ApiException(L::t('وُقّعت هذه الجهة.', 'This section is already signed.'), 'already_signed', 409);
        if (in_array($it['auto'] ?? null, ['handover', 'assets'], true)) throw new ApiException(L::t('هذا البند يكتمل من النظام.', 'This item is completed by the system.'), 'auto_item', 409);
        $d = Validator::make($in, ['state' => ['required', 'in:done,none,missing'], 'note' => ['nullable', 'string', 'max:500'], 'value' => ['nullable', 'numeric', 'min:0']])->validate();
        if ($d['state'] === 'missing' && mb_strlen(trim((string) ($d['note'] ?? ''))) < 3) throw ApiException::field('note', L::t('اذكر ما لم يُسلَّم.', 'Describe what is missing.'), 'note_required');
        $c = $r->clearance ?: $this->blankClearance();
        $c[$section]['items'][$item] = ['state' => $d['state'], 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'value' => ($it['amount'] ?? false) ? round((float) ($d['value'] ?? 0), 2) : null,
            'by' => $u->displayName('ar'), 'at' => now()->toIso8601String()];
        $r->update(['clearance' => $c]);
        return $r;
    }

    public function signSection(Resignation $r, User $u, string $section): Resignation
    {
        $this->inClearance($r);
        $s = collect(self::clearance($r))->firstWhere('key', $section) ?? abort(404);
        if (! self::canSign($u, $s['role'], $r)) throw new ApiException(L::t('هذه الجهة ليست من صلاحيتك.', 'This section is not yours.'), 'forbidden', 403);
        if ($s['signed']) throw new ApiException(L::t('وُقّعت هذه الجهة.', 'This section is already signed.'), 'already_signed', 409);
        if (! $s['ready']) throw new ApiException(L::t('أشّر كل البنود أولاً (المهام تكتمل بتوقيع F-05، والأصول باسترجاعها من سجل العهد).', 'Mark every item first (tasks complete with F-05, assets by returning them in custody).'), 'section_incomplete', 409);
        $c = $r->clearance ?: $this->blankClearance();
        $c[$section]['signed'] = ['by' => $u->displayName('ar'), 'by_en' => $u->displayName('en'), 'at' => now()->toIso8601String()];
        $r->update(['clearance' => $c]);
        Employee::find($r->employee_id)?->log("تسليم العهدة F-07 — توقيع جهة {$s['ar']} ({$u->displayName('ar')})", "Clearance F-07 — {$s['en']} signed ({$u->displayName('en')})", 'info', 'F-07');
        // اكتمال الجهات كلها ← براءة الذمة F-04
        if (collect(self::clearance($r->refresh()))->every(fn ($x) => $x['signed'])) {
            $y = now()->year;
            $r->update(['clearance_no' => 'HR-'.Counters::next("clearance-{$y}").'/'.$y, 'clearance_issued_at' => now()]);
            Employee::find($r->employee_id)?->log("براءة ذمة {$r->clearance_no}", "Clearance certificate {$r->clearance_no}", 'ok', 'F-04');
            Notify::user(User::where('employee_id', $r->employee_id)->value('id'), "صدرت براءة ذمتك {$r->clearance_no}", "Your clearance certificate {$r->clearance_no} was issued", 'resignation');
        }
        return $r;
    }

    private function inClearance(Resignation $r): void
    {
        if ($r->status !== 'clearance') throw new ApiException(L::t('هذه الإجراءات بعد اعتماد الطلب وقبل إغلاقه.', 'These steps follow the approval and precede closing.'), 'not_clearance', 409);
    }

    // ── مقابلة الخروج ──

    public function saveExit(Resignation $r, User $u, array $in): Resignation
    {
        if (! in_array($r->status, ['clearance', 'closed'], true)) throw new ApiException(L::t('مقابلة الخروج بعد اعتماد الطلب.', 'The exit interview follows the approval.'), 'not_clearance', 409);
        $d = Validator::make($in, ['answers' => ['nullable', 'array'], 'answers.*' => ['nullable', 'string', 'max:2000'], 'rating' => ['required', 'integer', 'between:1,5'], 'would_return' => ['required', 'boolean'],
            'rehire' => ['required', 'boolean'], 'rehire_reason' => ['nullable', 'string', 'max:1000'], 'noncompete' => ['required', 'boolean'], 'rights' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:4000']])->validate();
        if (! $d['rehire'] && mb_strlen(trim((string) ($d['rehire_reason'] ?? ''))) < 5) throw ApiException::field('rehire_reason', L::t('اذكر سبب عدم الأهلية لإعادة التعيين.', 'Give the reason the employee is not eligible for rehire.'), 'rehire_reason_required');
        $answers = array_intersect_key(array_filter($d['answers'] ?? [], fn ($v) => trim((string) $v) !== ''), array_flip(self::keys('questions')));
        $r->update(['exit' => ['answers' => $answers, 'rating' => (int) $d['rating'], 'would_return' => (bool) $d['would_return'], 'noncompete' => (bool) $d['noncompete'], 'rights' => (bool) $d['rights'],
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'at' => now()->toIso8601String(), 'by' => $u->displayName('ar'), 'by_en' => $u->displayName('en')],
            'rehire' => (bool) $d['rehire'], 'rehire_reason' => trim((string) ($d['rehire_reason'] ?? '')) ?: null]);
        return $r;
    }

    // ── المخالصة F-33 ──

    /** تقديري من بيانات النظام: أجر أيام الشهر الأخير + بدل الرصيد − عهد غير مسترجعة − سلف مفتوحة − ذمم المالية − بدل الإشعار + إعانة الوفاة */
    public static function settlement(Resignation $r): array
    {
        $e = Employee::findOrFail($r->employee_id);
        $last = $r->last_day->toDateString();
        $pay = Settings::policy('payroll', $last);
        $p = Settings::policy('exit', $last);
        $salary = (float) $e->salary;
        $daily = $salary / max(1, (int) $pay['month_days']);
        $days = (int) substr($last, 8, 2);
        // أيام الشهر الأخير منذ المباشرة إن بدأ في نفس الشهر
        if ($e->hire_date && $e->hire_date->format('Y-m') === substr($last, 0, 7)) $days = max(0, $days - (int) $e->hire_date->format('d') + 1);
        $bal = app(\App\Services\Leave\LeaveService::class)->balance($e);
        $remaining = max(0, (float) $bal['remaining']);
        $assets = \App\Models\Asset::where('employee_id', $e->id)->whereIn('status', ['assigned', 'pending_ack'])->sum('value');
        $advances = (float) \App\Models\Advance::where('employee_id', $e->id)->where('status', 'open')->sum('amount');
        $finance = 0.0;
        foreach (($r->clearance['finance']['items'] ?? []) as $it) $finance += (float) ($it['value'] ?? 0);
        $short = $r->kind === 'resignation' && ! $r->notice_waived && $r->notice_until && $last < $r->notice_until->toDateString();
        $noticePay = $short ? $salary * (float) $p['notice_pay_months'] : 0.0;
        $death = ($r->termination['cause'] ?? null) === 'death' ? $salary * (float) $p['death_grant_months'] + max(0, $salary - $days * $daily) : 0.0;
        $m = ['daily' => $daily, 'days' => $days, 'pro_rata' => $days * $daily, 'leave_days' => $remaining, 'leave_pay' => $remaining * $daily, 'death_grant' => $death,
            'assets' => (float) $assets, 'advances' => $advances, 'finance' => $finance, 'notice_short' => $short, 'notice_pay' => $noticePay];
        $m['total'] = $m['pro_rata'] + $m['leave_pay'] + $m['death_grant'] - $m['assets'] - $m['advances'] - $m['finance'] - $m['notice_pay'];
        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $m) + ['currency' => $e->currency];
    }

    // ── نسخ الحساب للبديل (D-14) ──

    public static function needsSuccessor(Resignation $r): bool
    {
        $u = User::with('roles')->where('employee_id', $r->employee_id)->first();
        return $u && $u->roles->pluck('key')->diff(['employee'])->isNotEmpty();
    }

    /** يُنسخ للبديل الدور والصلاحيات، ويأخذ كلمة سر مؤقتة إن طُلب؛ الحساب القديم يُعطَّل عند الإغلاق (أو فوراً إن أُغلق) */
    public function successor(Resignation $r, User $by, int $userId, bool $reset): array
    {
        if (! Perm::can($by, 'users', 'edit')) throw new ApiException(L::t('نقل الحساب لمدير النظام.', 'Only the system administrator can move the account.'), 'forbidden', 403);
        if (! in_array($r->status, ['clearance', 'closed'], true)) throw new ApiException(L::t('بعد اعتماد الطلب.', 'After the approval.'), 'not_clearance', 409);
        $old = User::with('roles')->where('employee_id', $r->employee_id)->first();
        $new = User::with('roles', 'employee')->where('active', true)->whereKey($userId)->first();
        if (! $new || ! $new->employee || $new->employee_id === $r->employee_id || $new->employee->status === 'terminated' || $new->employee->status === 'resigning') {
            throw ApiException::field('user_id', L::t('اختر حساب موظف على رأس عمله.', 'Choose the account of a current employee.'), 'invalid');
        }
        return DB::transaction(function () use ($r, $by, $old, $new, $reset) {
            $roles = $old ? $old->roles->filter(fn ($x) => $x->key !== 'employee') : collect();
            foreach ($roles as $role) if (! $new->roles->contains('id', $role->id)) $new->roles()->attach($role->id);
            $password = null;
            if ($reset) {
                $password = \Illuminate\Support\Str::password(12, symbols: false);
                $new->forceFill(['password' => $password, 'must_change_password' => true])->save();
            }
            $r->update(['successor_user_id' => $new->id, 'account_moved_at' => now()]);
            if ($r->status === 'closed' && $old) $old->forceFill(['active' => false])->save();
            Audit::record('account.transferred', $r, null, ['from' => $old?->id, 'to' => $new->id, 'roles' => $roles->pluck('key')->values()->all(), 'password_reset' => $reset]);
            Employee::find($r->employee_id)?->log("نسخ الحساب والصلاحيات إلى {$new->displayName('ar')}", "Account and permissions moved to {$new->displayName('en')}", 'info');
            Notify::user($new->id, 'أُضيفت إلى حسابك صلاحيات '.($old?->displayName('ar') ?? ''), 'Permissions of '.($old?->displayName('en') ?? '').' were added to your account', null);
            return ['roles' => $roles->pluck('key')->values()->all(), 'password' => $password];
        });
    }

    // ── الإغلاق ──

    public function close(Resignation $r, User $u): Resignation
    {
        $this->inClearance($r);
        if (now()->toDateString() < $r->last_day->toDateString()) throw new ApiException(L::t('يُغلق الملف في آخر يوم عمل أو بعده — لتقديمه استخدم الإعفاء من الإخطار.', 'Close on or after the last working day — to bring it forward, waive the notice.'), 'before_last_day', 409);
        if (! $r->clearance_no) throw new ApiException(L::t('أكمل تسليم العهدة F-07 بكل جهاتها أولاً.', 'Complete every section of the clearance F-07 first.'), 'clearance_incomplete', 409);
        $cause = $r->termination['cause'] ?? null;
        if ($r->kind === 'resignation' && empty($r->exit)) throw new ApiException(L::t('سجّل مقابلة الخروج أولاً.', 'Record the exit interview first.'), 'exit_interview_required', 409);
        if (! $r->successor_user_id && self::needsSuccessor($r) && $cause !== 'death') throw new ApiException(L::t('حدّد الحساب البديل لنقل الصلاحيات أولاً (مدير النظام).', 'The administrator must choose a successor account first.'), 'successor_required', 409);
        return DB::transaction(function () use ($r, $u) {
            $e = Employee::findOrFail($r->employee_id);
            $st = self::settlement($r);
            $p = Settings::policy('exit');
            $r->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $u->id, 'settlement' => $st, 'settlement_total' => $st['total'],
                'followups' => ['labor' => ['done' => false], 'insurance' => ['done' => false, 'due' => Carbon::parse($r->last_day->toDateString())->addDays((int) $p['insurance_days'])->toDateString()],
                    'informed' => ['done' => true, 'at' => now()->toIso8601String()]]]);
            $e->update(['status' => 'terminated', 'end_date' => $r->last_day]);
            // إلغاء طلباته المعلّقة
            foreach ([LeaveRequest::class, ServiceRequest::class, OvertimeRequest::class] as $cls) {
                $cls::where('employee_id', $e->id)->whereIn('status', LeaveRequest::PENDING)->get()->each(function ($x) {
                    RequestStep::where('subject_type', $x->getMorphClass())->where('subject_id', $x->id)->whereIn('status', ['pending', 'waiting'])->update(['status' => 'cancelled', 'note' => 'end of service']);
                    $x->update(['status' => 'cancelled']);
                });
            }
            User::where('employee_id', $e->id)->update(['active' => false]);   // تنتهي جلساته (ResolveTenant)
            $e->log("انتهت الخدمة {$r->ref_no} — المخالصة {$st['total']} {$st['currency']}", "Service ended {$r->ref_no} — settlement {$st['total']} {$st['currency']}", 'danger', 'F-33');
            // إعلام كل الموظفين
            foreach (User::where('company_id', \App\Support\Tenant::id())->where('active', true)->whereNotNull('employee_id')->pluck('id') as $id) Notify::user($id, "انتهت خدمة {$e->name} في الشركة — نشكره على ما قدّم", "{$e->name_en} has left the company — thank you for everything", null);
            Audit::record('exit.closed', $r, null, ['total' => $st['total'], 'kind' => $r->kind]);
            return $r->refresh();
        });
    }

    /** مهام ما بعد الخروج: توثيق مديرية العمل، والانفكاك من التأمينات */
    public function followup(Resignation $r, User $u, string $key, bool $done): Resignation
    {
        if ($r->status !== 'closed' || ! in_array($key, ['labor', 'insurance'], true)) abort(409);
        $f = $r->followups ?? [];
        $f[$key] = ['done' => $done, 'at' => $done ? now()->toIso8601String() : null, 'by' => $done ? $u->displayName('ar') : null] + array_intersect_key($f[$key] ?? [], ['due' => 1]);
        $r->update(['followups' => $f]);
        return $r;
    }

    // ── شهادة الخدمة F-06 ──

    public function serviceCertificate(Resignation $r, User $u): Resignation
    {
        if (! in_array($r->status, ['clearance', 'closed'], true)) throw new ApiException(L::t('بعد اعتماد الطلب.', 'After the approval.'), 'not_clearance', 409);
        if (! $r->service_cert_no) {
            $y = now()->year;
            $r->update(['service_cert_no' => 'HR/'.Counters::next("service-cert-{$y}").'/'.$y, 'service_cert_at' => now()]);
            Audit::record('certificate.issued', $r, null, ['ref' => $r->service_cert_no, 'kind' => 'service']);
        }
        return $r;
    }

    // ── إشعار الجهة الخارجية (D-17) ──

    private function prepareNotices(Resignation $r, Employee $e): void
    {
        ServiceRequest::where('employee_id', $e->id)->where('kind', 'salary_cert')->whereNotNull('ref_no')->get()->each(function (ServiceRequest $s) use ($r, $e) {
            if (empty($s->addressee['email']) || ExitNotice::where('resignation_id', $r->id)->where('service_request_id', $s->id)->exists()) return;
            ExitNotice::create(['resignation_id' => $r->id, 'employee_id' => $e->id, 'service_request_id' => $s->id, 'addressee' => $s->addressee, 'consent' => (bool) $s->notify_consent,
                'status' => $s->notify_consent ? 'prepared' : 'blocked']);
            if (! $s->notify_consent) Notify::hr("شهادة راتب سابقة لـ {$e->name} إلى «{$s->addressee['name']}» بلا موافقة على الإبلاغ — لن يُرسل إشعار", "A salary certificate for {$e->name_en} to «{$s->addressee['name']}» has no consent — no notice will be sent", 'offboarding:'.$r->id);
        });
    }

    public function sendNotice(ExitNotice $n, User $u): ExitNotice
    {
        if ($n->status !== 'prepared' || ! $n->consent) throw new ApiException(L::t('لا يُرسل إشعار بلا موافقة موثقة، أو سبق إرساله.', 'No notice is sent without documented consent, or it was already sent.'), 'notice_blocked', 409);
        $r = Resignation::findOrFail($n->resignation_id);
        if (! in_array($r->status, ['clearance', 'closed'], true)) throw new ApiException(L::t('بعد اعتماد الطلب.', 'After the approval.'), 'not_clearance', 409);
        $e = Employee::findOrFail($n->employee_id);
        $co = \App\Support\Tenant::company();
        \Illuminate\Support\Facades\Notification::route('mail', $n->addressee['email'])->notify((new \App\Notifications\ExitNoticeMail($co->name_ar, $co->name_en, $e->name, $e->name_en, $r->last_day->toDateString(), $n->addressee['name'] ?? ''))->afterCommit());
        $n->update(['status' => 'sent', 'approved_by' => $u->id, 'sent_at' => now()]);
        Audit::record('exit.notice_sent', $n, null, ['to' => $n->addressee['email']]);
        return $n;
    }

    // ── تقرير الأسباب الشهري (M13) ──

    public static function report(string $from, string $to): array
    {
        $rows = Resignation::with('employee.department')->where('kind', 'resignation')->whereIn('status', ['clearance', 'closed'])->whereBetween('decided_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $names = collect(self::lists()['reasons'])->keyBy('key');
        $reason = fn ($r) => $r->hr_interview['reasons'] ?? $r->reasons ?? [$r->reason_key];
        $byReason = [];
        foreach ($rows as $r) foreach ($reason($r) as $k) $byReason[$k] = ($byReason[$k] ?? 0) + 1;
        arsort($byReason);
        return ['count' => $rows->count(), 'terminations' => Resignation::where('kind', 'termination')->whereIn('status', ['clearance', 'closed'])->whereBetween('decided_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
            'reasons' => collect($byReason)->map(fn ($n, $k) => ['key' => $k, 'ar' => $names[$k]['ar'] ?? $k, 'en' => $names[$k]['en'] ?? $k, 'count' => $n])->values(),
            'departments' => $rows->groupBy(fn ($r) => $r->employee?->department_id)->map(fn ($g) => ['ar' => $g->first()->employee?->department?->name_ar, 'en' => $g->first()->employee?->department?->name_en, 'count' => $g->count(),
                'reasons' => collect($g)->flatMap($reason)->countBy()->sortDesc()->keys()->take(3)->map(fn ($k) => ['ar' => $names[$k]['ar'] ?? $k, 'en' => $names[$k]['en'] ?? $k])->values()])->values(),
            'rating' => round((float) $rows->filter(fn ($r) => isset($r->exit['rating']))->avg(fn ($r) => $r->exit['rating']), 1),
            'would_return' => $rows->filter(fn ($r) => ! empty($r->exit['would_return']))->count(), 'rehire' => $rows->where('rehire', true)->count()];
    }
}
