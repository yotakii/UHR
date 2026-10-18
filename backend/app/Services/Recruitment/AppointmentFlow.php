<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\AppointmentApproval;
use App\Models\Asset;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CandidateMessage;
use App\Services\Onboarding\F19;
use App\Services\Referrals\ReferralPoints;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * قرار التعيين F-31 (approveAppointment في الديمو) — قرار 16 تشرين الأول: كل مستوى يعتمده صاحبه من حسابه
 * (المستخدم المحدد في السلسلة، وإلا أي حساب في الشركة يحمل دور المستوى)، بالترتيب. الرفض بسبب إلزامي ينهي الطلب.
 * اكتمال المستويات ← ملف الموظف (رقم وظيفي، حساب المتقدم يصبح حساب موظف، بطاقة NFC من المخزون، المباشرة F-19).
 */
class AppointmentFlow
{
    /** المستوى الحالي (أول مستوى بانتظار) */
    public static function current(Appointment $ap): ?AppointmentApproval
    {
        return $ap->approvals->firstWhere('status', 'pending');
    }

    public static function canDecide(User $user, AppointmentApproval $step): bool
    {
        if ($step->approver_id) return $step->approver_id === $user->id;
        $key = self::roleKey($step);
        return $key && $user->hasRole($key);
    }

    /** دور المستوى من سلسلة الاعتماد (للمستويات بلا صاحب محدد) */
    public static function roleKey(AppointmentApproval $step): ?string
    {
        $levels = \App\Models\ApprovalChain::where('type', 'appointment')->value('levels') ?? [];
        return $levels[$step->level - 1]['role_key'] ?? null;
    }

    public function decide(AppointmentApproval $step, User $user, string $decision, ?string $note): Appointment
    {
        $ap = $step->appointment()->with(['approvals', 'candidate.jobOpening', 'candidate.offer', 'candidate.user'])->firstOrFail();
        $current = self::current($ap);
        if (! $current || $current->id !== $step->id) throw new ApiException(L::t('هذا المستوى ليس دوره الآن.', 'This level is not up for decision now.'), 'not_current_level', 409);
        if (! self::canDecide($user, $step)) throw new ApiException(L::t('هذا الاعتماد ليس من صلاحيتك.', 'This approval is not yours to give.'), 'not_your_level', 403);
        if ($decision === 'rejected' && mb_strlen(trim((string) $note)) < 10) throw ApiException::field('reason', L::t('اكتب سبب الرفض (10 أحرف على الأقل).', 'Write the reason for rejecting (at least 10 characters).'), 'reason_required');

        $c = $ap->candidate;
        $name = $user->displayName('ar'); $nameEn = $user->displayName('en');
        DB::transaction(function () use ($ap, $step, $user, $decision, $note, $c, $name, $nameEn) {
            $step->update(['status' => $decision, 'approved_at' => now(), 'approved_by' => $user->id, 'approver_name' => $name]);
            $n = $note ? ' — '.trim($note) : '';
            if ($decision === 'approved') {
                $c->log("اعتماد قرار التعيين — {$step->role_ar}: {$name}{$n}", "Appointment approved — {$step->role_en}: {$nameEn}{$n}", 'ok', 'F-31');
                $ap->load('approvals');
                if ($ap->approvals->every(fn ($a) => $a->status === 'approved')) $this->hire($ap, $c);
                else {
                    $next = self::current($ap);
                    Notify::hr("قرار تعيين {$c->name} بانتظار اعتماد {$next->role_ar}", 'Appointment of '.($c->name_en ?: $c->name)." awaits {$next->role_en}", 'approvals');
                    if ($next->approver_id) Notify::user($next->approver_id, "قرار تعيين {$c->name} بانتظار اعتمادك", 'Appointment of '.($c->name_en ?: $c->name).' awaits your approval', 'approvals');
                }
            } else {
                $c->update(['stage' => 'rejected', 'stage_at' => now()->toDateString(), 'reject_reason' => trim($note)]);
                $c->log("رفض قرار التعيين — {$step->role_ar}: {$name}{$n}", "Appointment rejected — {$step->role_en}: {$nameEn}{$n}", 'danger', 'F-31');
                (new ReferralPoints)->sync($c);
                Notify::hr("رُفض قرار تعيين {$c->name} من {$step->role_ar}", 'Appointment of '.($c->name_en ?: $c->name)." rejected by {$step->role_en}", "cand:{$c->id}");
            }
            Audit::record('appointment.'.$decision, $ap, null, ['level' => $step->level, 'role' => $step->role_en, 'note' => $note]);
        });
        return $ap->refresh()->load('approvals');
    }

    /** اكتمال القرار: ملف الموظف والمباشرة */
    private function hire(Appointment $ap, Candidate $c): Employee
    {
        $job = $c->jobOpening; $o = $c->offer;
        if ($c->national_id && Employee::where('national_id', $c->national_id)->exists()) {
            throw new ApiException(L::t('يوجد موظف مسجّل بنفس الرقم الوطني — راجع الملفات قبل الاعتماد النهائي.', 'An employee with the same national ID exists — check the records before the final approval.'), 'duplicate_employee', 409);
        }
        $mgr = Employee::where('department_id', $job->department_id)->whereHas('user.roles', fn ($r) => $r->where('key', 'manager'))->value('id');
        $emp = Employee::create([
            'code' => Counters::employeeCode(), 'name' => $c->name, 'name_en' => $c->name_en ?: $c->name, 'gender' => $c->gender,
            'title_ar' => $o?->title ?: $job->title_ar, 'title_en' => $job->title_en, 'department_id' => $job->department_id, 'location_id' => $job->location_id,
            'email' => $c->email, 'phone' => $c->mobile, 'hire_date' => $o?->start_date ?? now()->toDateString(), 'dob' => $c->dob, 'national_id' => $c->national_id,
            'salary' => $o?->total() ?? 0, 'currency' => $o?->currency ?? 'USD', 'status' => 'onboarding', 'nfc_uid' => Employee::newNfcUid(), 'manager_id' => $mgr, 'candidate_id' => $c->id,
        ]);
        foreach ($c->logs()->orderBy('at')->get() as $l) $emp->log($l->text_ar, $l->text_en, $l->kind, $l->form, $l->at);
        $emp->log("صدور قرار التعيين {$ap->decision_no} ومنح الرقم الوظيفي {$emp->code}", "Decision {$ap->decision_no} issued, employee ID {$emp->code}", 'ok', 'F-31');
        Onboarding::create(['employee_id' => $emp->id, 'checklist' => F19::blank(0, 8)]);
        $ap->update(['employee_id' => $emp->id]);
        $c->update(['stage' => 'hired', 'stage_at' => now()->toDateString()]);
        $c->log("تم التعيين — الرقم الوظيفي {$emp->code}", "Hired — employee ID {$emp->code}", 'ok');

        // حساب المتقدم نفسه يصبح حساب الموظف («الدخول كموظف» في بوابته)
        if ($u = $c->user) {
            $u->update(['employee_id' => $emp->id, 'company_id' => $c->company_id]);
            $u->roles()->syncWithoutDetaching(Role::whereIn('key', ['employee'])->pluck('id'));
            $co = $job->company()->first();
            $u->notify(new CandidateMessage('hired', ['ar' => ['مبارك تعيينك', 'اكتمل قرار تعيينك على وظيفة :job. رقمك الوظيفي: '.$emp->code, 'ادخل بنفس حسابك لتجد بوابة الموظف وإجراءات المباشرة.', 'الدخول للبوابة'],
                'en' => ['Congratulations on your appointment', 'Your appointment for :job is complete. Your employee ID: '.$emp->code, 'Sign in with the same account to find the employee portal and your onboarding.', 'Sign in']],
                $job->title_ar, $job->title_en, $c->ref, ['ar' => $co->name_ar, 'en' => $co->name_en], '/'));
        }
        // بطاقة الحضور NFC من المخزون — بانتظار توقيع الاستلام
        Asset::whereHas('category', fn ($q) => $q->where('code', 'cards'))->where('status', 'available')->orderBy('id')->first()?->update(['employee_id' => $emp->id, 'status' => 'pending_ack', 'assigned_at' => now()]);
        // الإحالة: نقاط المرحلة 3 (توقيع العرض ومباشرة العمل) — والمرحلة 4 عند تثبيت التعيين
        (new ReferralPoints)->sync($c);
        if ($c->internal_employee_id) Employee::find($c->internal_employee_id)?->log("قبول طلب النقل/الترقية الداخلي إلى {$job->title_ar}", "Internal move to {$job->title_en} approved", 'ok', 'F-27');
        Notify::hr("اكتمل قرار تعيين {$c->name} — بدأت إجراءات المباشرة", ($c->name_en ?: $c->name).' appointed — onboarding started', "onboard:{$emp->id}");
        return $emp;
    }
}
