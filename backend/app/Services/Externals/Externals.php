<?php

namespace App\Services\Externals;

use App\Exceptions\ApiException;
use App\Models\ApprovalChain;
use App\Models\CodeItem;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\External;
use App\Models\PaymentRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VolunteerLog;
use App\Services\Accounts\Invites;
use App\Services\Codes\Codes;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Signature;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * العاملون من غير الموظفين (SRS الأنظمة الأساسية E.1–E.3): فئة العامل تقرر كل شيء، والتكليف يمر بفحص طبيعة العلاقة،
 * والمتطوع لا يسجّل ساعات قبل توقيع الاتفاقية، والساعات يصادقها المشرف، وعند الانتهاء شهادة برمز تحقق وتعطيل الحساب.
 */
class Externals
{
    public const RELATIONS = ['replaces', 'alongside', 'independent'];
    public const TEST = ['fixed_hours', 'daily_orders', 'monthly_pay', 'exclusive', 'fills_post', 'uses_premises'];
    public const VOL_STATUS = ['applicant', 'accepted', 'waitlist', 'pending_sign', 'active', 'ended', 'suspended', 'excluded'];
    public const EXP_STATUS = ['applicant', 'vetting', 'approved', 'assigned', 'inactive', 'excluded', 'rejected'];

    public function __construct(private Workflow $wf) {}

    public static function cfg(): array { return Settings::merged('externals'); }

    public static function seedChains(): void
    {
        $c = self::cfg();
        ApprovalChain::firstOrCreate(['type' => 'engagement'], ['levels' => [Workflow::level($c['approver'])]]);
        ApprovalChain::firstOrCreate(['type' => 'engagement_high'], ['levels' => [Workflow::level($c['approver']), Workflow::level($c['high_approver'])]]);
    }

    public static function canManage(User $u): bool { return Perm::can($u, 'externals', 'edit') && Perm::scope($u, 'externals') === 'all'; }
    public static function canFees(User $u): bool { $u->loadMissing('roles'); return $u->hasRole('admin', ...self::cfg()['fee_roles']); }
    private static function min(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    /** الفئة ← نوع غير الموظف؛ الفئة التي تدخل الرواتب مكانها ملفات الموظفين */
    public static function kindOf(CodeItem $cat): string
    {
        if ($cat->attr('payroll', false)) throw ApiException::field('worker_category_id', L::t('هذه الفئة تدخل الرواتب — أضفها من «الموظفون».', 'This category is in payroll — add it under “Employees”.'), 'category_in_payroll');
        return match ((string) $cat->attr('portal', 'none')) {
            'volunteer' => 'volunteer', 'expert' => $cat->code === 'CNS' ? 'consultant' : 'expert', 'trainee' => 'intern', default => 'trial',
        };
    }

    public static function isExpert(External $x): bool { return in_array($x->kind, ['expert', 'consultant'], true); }

    // ———————————————— السجل ————————————————

    public function save(array $v, ?User $u, ?External $x = null, string $source = 'internal'): External
    {
        $cat = Codes::resolve('worker_categories', $v['worker_category_id'] ?? $x?->worker_category_id) ?? throw ApiException::field('worker_category_id', L::t('اختر فئة العامل.', 'Choose the worker category.'), 'required');
        $kind = self::kindOf($cat);
        if ($x && $x->kind !== $kind && $x->engagements()->exists()) throw ApiException::field('worker_category_id', L::t('لا يمكن تغيير النوع بعد وجود تكليفات.', 'The type cannot change once there are engagements.'), 'kind_locked');
        if (! empty($v['email']) && External::where('email', $v['email'])->where('kind', $kind)->when($x, fn ($q) => $q->whereKeyNot($x->id))->exists())
            throw ApiException::field('email', L::t('البريد مسجّل لشخص آخر في هذا السجل.', 'This email is already in this register.'), 'duplicate');
        if (! empty($v['specialties'])) foreach ($v['specialties'] as $sid) if (! Codes::resolve('specialties', $sid)) throw ApiException::field('specialties', L::t('تخصص غير موجود في الدليل.', 'Unknown specialty.'), 'invalid');
        $data = array_intersect_key($v, array_flip(['entity', 'name', 'name_en', 'gender', 'dob', 'national_id', 'nationality', 'email', 'phone', 'country', 'city', 'emergency', 'areas', 'availability',
            'specialties', 'sectors', 'keywords', 'languages', 'years', 'level', 'projects', 'available', 'available_from', 'work_modes', 'location_id', 'department_id', 'supervisor_id', 'cv_file_id', 'documents', 'opportunity_id']));
        if ($u && self::canFees($u)) $data += array_intersect_key($v, array_flip(['rate', 'rate_unit', 'rate_currency', 'notes']));
        $data['worker_category_id'] = $cat->id; $data['kind'] = $kind;
        if (! $x) {
            $data += ['ref' => 'EXT-'.now()->year.'-'.str_pad((string) Counters::next('external-'.now()->year), 4, '0', STR_PAD_LEFT), 'source' => $source, 'created_by' => $u?->id,
                'status' => self::isExpert(new External(['kind' => $kind])) ? ($source === 'public' ? 'applicant' : 'vetting') : ($source === 'public' ? 'applicant' : 'accepted'),
                'review_due' => self::isExpert(new External(['kind' => $kind])) ? now()->addMonths((int) self::cfg()['roster_review_months'])->toDateString() : null];
            $x = External::create($data);
            if ($source === 'public') Notify::hr(self::isExpert($x) ? "طلب إدراج في قائمة الخبراء: {$x->name}" : "طلب تطوع جديد: {$x->name}", self::isExpert($x) ? "Roster application: {$x->name}" : "New volunteer application: {$x->name}", 'externals:'.$x->id);
        } else $x->update($data);
        return $x->fresh();
    }

    /** الحالة: قبول/قائمة انتظار/إيقاف/استبعاد (متطوع) — تدقيق/اعتماد/رفض/تعطيل (خبير) */
    public function setStatus(External $x, string $status, ?string $note, User $u): External
    {
        $allowed = self::isExpert($x) ? self::EXP_STATUS : self::VOL_STATUS;
        if (! in_array($status, $allowed, true) || in_array($status, ['pending_sign', 'active', 'assigned'], true)) throw new ApiException(L::t('حالة غير مسموحة هنا.', 'Status not allowed here.'), 'invalid', 422);
        if (in_array($status, ['rejected', 'excluded', 'suspended'], true) && mb_strlen(trim((string) $note)) < self::min()) throw ApiException::field('note', L::t('اكتب السبب.', 'Write the reason.'), 'reason_required');
        if ($status === 'approved' && ! $x->cv_file_id && ! $x->documents) throw new ApiException(L::t('الإدراج يتطلب السيرة الذاتية أو الشهادات (POL-RS-02).', 'Listing needs a CV or certificates (POL-RS-02).'), 'documents_required', 422);
        $x->update(['status' => $status, 'status_note' => $note] + ($status === 'approved' ? ['review_due' => now()->addMonths((int) self::cfg()['roster_review_months'])->toDateString(), 'review_requested_at' => null] : []));
        if (in_array($status, ['excluded', 'suspended', 'inactive'], true) && $x->user_id) User::whereKey($x->user_id)->update(['active' => false]);
        Audit::record('external.status', $x, ['status' => $status, 'note' => $note]);
        return $x;
    }

    // ———————————————— التكليف والاتفاقية ————————————————

    public function engage(External $x, array $v, User $u, ?Engagement $g = null): Engagement
    {
        if (self::isExpert($x)) throw new ApiException(L::t('الخبراء يُكلَّفون من «التكليفات».', 'Experts are engaged through “Assignments”.'), 'use_assignments', 422);
        if ($g && $g->status !== 'draft' && $g->status !== 'rejected') throw new ApiException(L::t('لا يُعدَّل التكليف بعد إرساله.', 'The engagement cannot change after it is sent.'), 'locked', 409);
        if (! $g && Engagement::where('external_id', $x->id)->whereNotIn('status', ['ended', 'terminated', 'cancelled', 'rejected'])->exists()) throw new ApiException(L::t('لهذا الشخص تكليف قائم.', 'This person already has an open engagement.'), 'engagement_open', 409);
        $c = self::cfg();
        $from = Carbon::parse($v['starts_on']); $to = Carbon::parse($v['ends_on']);
        if ($to->lt($from)) throw ApiException::field('ends_on', L::t('تاريخ النهاية قبل البداية.', 'End is before start.'), 'invalid');
        $months = $from->diffInMonths($to->copy()->addDay());
        if ($x->kind === 'volunteer' && $months > (int) $c['max_months']) throw ApiException::field('ends_on', L::t("مدة التطوع القصوى {$c['max_months']} أشهر.", "The maximum volunteering period is {$c['max_months']} months."), 'too_long');
        if ($x->kind === 'volunteer' && (int) $v['weekly_hours'] > (int) $c['max_weekly_hours']) throw ApiException::field('weekly_hours', L::t("الحد الأعلى {$c['max_weekly_hours']} ساعة أسبوعياً.", "The maximum is {$c['max_weekly_hours']} hours a week."), 'too_many_hours');
        if (! in_array($v['relation'] ?? '', self::RELATIONS, true)) throw ApiException::field('relation', L::t('حدد العلاقة التشغيلية.', 'Choose the operational relation.'), 'required');
        if ($v['relation'] === 'replaces') {
            if (empty($v['replaces_employee_id']) && empty($v['replaces_position'])) throw ApiException::field('replaces_position', L::t('حدد الموظف أو الموقع الوظيفي.', 'Specify the employee or position.'), 'required');
            if (mb_strlen(trim((string) ($v['justification'] ?? ''))) < self::min()) throw ApiException::field('justification', L::t('حالة «يحل محل موظف» تتطلب مبرراً.', '“Replaces an employee” needs a justification.'), 'reason_required');
            if ($months > (int) $c['replaces_max_months']) throw ApiException::field('ends_on', L::t("حالة «يحل محل موظف» لا تتجاوز {$c['replaces_max_months']} أشهر.", "“Replaces an employee” cannot exceed {$c['replaces_max_months']} months."), 'too_long');
        }
        $test = array_map(fn ($k) => (bool) (($v['relation_test'] ?? [])[$k] ?? false), array_combine(self::TEST, self::TEST));
        $fee = $x->kind === 'volunteer' ? (in_array($v['fee_model'] ?? 'none', ['none', 'expenses'], true) ? ($v['fee_model'] ?? 'none') : throw ApiException::field('fee_model', L::t('التطوع دون أجر — يجوز تعويض المصاريف فقط.', 'Volunteering is unpaid — only expenses may be reimbursed.'), 'unpaid')) : ($v['fee_model'] ?? 'none');
        $data = ['title' => $v['title'], 'tasks' => $v['tasks'] ?? null, 'department_id' => $v['department_id'] ?? $x->department_id, 'location_id' => $v['location_id'] ?? $x->location_id,
            'supervisor_id' => $v['supervisor_id'], 'starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(), 'weekly_hours' => (int) $v['weekly_hours'], 'days' => $v['days'] ?? null,
            'relation_employee_id' => $v['relation'] === 'alongside' ? ($v['relation_employee_id'] ?? null) : null, 'project' => $v['project'] ?? null,
            'mission_id' => ! empty($v['mission_id']) && \App\Models\Mission::whereKey((int) $v['mission_id'])->exists() ? (int) $v['mission_id'] : null,
            'relation' => $v['relation'], 'replaces_employee_id' => $v['relation'] === 'replaces' ? ($v['replaces_employee_id'] ?? null) : null, 'replaces_position' => $v['relation'] === 'replaces' ? ($v['replaces_position'] ?? null) : null,
            'relation_test' => $test, 'relation_score' => count(array_filter($test)), 'justification' => $v['justification'] ?? null, 'fee_model' => $fee,
            'amount' => $fee === 'none' || $fee === 'expenses' ? null : ($v['amount'] ?? null), 'currency' => $v['currency'] ?? $c['expense_currency'],
            'expense_cap' => $fee === 'expenses' ? ($v['expense_cap'] ?? $c['expense_cap']) : null, 'tax_treatment' => $v['tax_treatment'] ?? null];
        if ($x->kind === 'intern') {   // الستاج (SRS التدريب WF-06 / POL-22): النوع، الساعات المطلوبة، محطات التدوير
            $type = in_array($v['stage_type'] ?? 'free', ['paid', 'free', 'fee'], true) ? ($v['stage_type'] ?? 'free') : 'free';
            $max = (int) \App\Services\Training\Td::cfg()['stage_free_max_months'];
            if ($type === 'free' && $months > $max) throw ApiException::field('ends_on', L::t("الستاج المجاني لا يتجاوز {$max} أشهر.", "A free internship cannot exceed {$max} months."), 'too_long');
            $data += ['stage_type' => $type, 'required_hours' => (int) ($v['required_hours'] ?? 0) ?: null, 'stations' => $v['stations'] ?? null];
            $data['fee_model'] = $type === 'paid' ? 'monthly' : ($type === 'fee' ? 'lump' : 'none');
        }
        if (! Employee::whereKey($data['supervisor_id'])->exists()) throw ApiException::field('supervisor_id', L::t('المشرف إلزامي (POL-VL-06).', 'A supervisor is required (POL-VL-06).'), 'required');
        if ($g) { $g->update($data + ['status' => 'draft']); return $g->fresh(); }
        $g = Engagement::create($data + ['ref' => 'ENG-'.now()->year.'-'.str_pad((string) Counters::next('engagement-'.now()->year), 4, '0', STR_PAD_LEFT), 'external_id' => $x->id, 'status' => 'draft', 'requested_by' => $u->id]);
        return $g;
    }

    /** يحتاج اعتماداً أعلى؟ حالة «يحل محل موظف» أو فحص طبيعة العلاقة تجاوز العتبة */
    public static function high(Engagement $g): bool { return $g->relation === 'replaces' || $g->relation_score >= (int) self::cfg()['relation_threshold']; }

    public function submit(Engagement $g, User $u): Engagement
    {
        if (! in_array($g->status, ['draft', 'rejected'], true)) throw new ApiException(L::t('أُرسل التكليف من قبل.', 'Already sent.'), 'locked', 409);
        \App\Models\RequestStep::where('subject_type', $g->getMorphClass())->where('subject_id', $g->id)->delete();
        $this->wf->start($g, null, Workflow::chain(self::high($g) ? 'engagement_high' : 'engagement'), $u);
        $g->refresh();
        if ($g->status === 'approved') $this->approved($g);
        return $g;
    }

    public function decide(Engagement $g, User $u, bool $approve, ?string $note): Engagement
    {
        $g = $this->wf->decide($g, $u, $approve, $note, false, fn (Engagement $x) => $this->approved($x));
        return $g->fresh();
    }

    /** معتمد ← نص الاتفاقية يُجمَّد، والحالة «بانتظار التوقيع»، وحساب بوابة بدعوة (لا كلمة مرور بالبريد) */
    private function approved(Engagement $g): void
    {
        $x = External::find($g->external_id);
        $g->update(['body' => self::render($g, $x, 'ar')."\n\n———\n\n".self::render($g, $x, 'en'), 'onboarding' => array_map(fn ($i) => $i + ['done' => false], self::cfg()['onboarding'])]);
        $x->update(['status' => 'pending_sign']);
        $this->account($x);
        if ($x->user_id) Notify::user($x->user_id, 'اعتُمد تكليفك — وقّع الاتفاقية لتبدأ', 'Your engagement is approved — sign the agreement to start', 'ext');
    }

    /** حساب البوابة المبسطة (دور volunteer أو expert) برابط تفعيل */
    public function account(External $x): ?User
    {
        if (! $x->email) return null;
        $role = self::isExpert($x) ? 'expert' : 'volunteer';
        $u = $x->user_id ? User::find($x->user_id) : User::where('email', $x->email)->where('company_id', $x->company_id)->first();
        if ($u && $u->employee_id) return null;   // موظف: لا حساب ثانٍ
        if (! $u) {
            if (User::where('email', $x->email)->exists()) return null;
            $u = User::create(['company_id' => $x->company_id, 'name' => $x->name, 'name_en' => $x->name_en, 'email' => $x->email, 'password' => Invites::unusable(), 'active' => true, 'locale' => 'ar', 'invited_at' => now()]);
            app(Invites::class)->issue($u, 'invite');
        }
        $rid = Role::where('key', $role)->value('id');
        if ($rid && ! $u->roles()->where('roles.id', $rid)->exists()) $u->roles()->attach($rid);
        if (! $u->active) $u->update(['active' => true]);
        $x->update(['user_id' => $u->id]);
        return $u;
    }

    public static function render(Engagement $g, External $x, string $lang): string
    {
        $c = self::cfg(); $company = \App\Models\Company::find($g->company_id);
        $sup = $g->supervisor_id ? Employee::find($g->supervisor_id) : null;
        $rel = ['replaces' => ['يحل محل موظف', 'Replaces an employee'], 'alongside' => ['يعمل إلى جانب موظف', 'Works alongside an employee'], 'independent' => ['مهمة مستقلة', 'Independent task']][$g->relation];
        $vars = [':company' => $lang === 'en' ? $company?->name_en : $company?->name_ar, ':name' => $lang === 'en' ? ($x->name_en ?: $x->name) : $x->name, ':national_id' => $x->national_id ?: '—',
            ':branch' => $g->location_id ? (($l = \App\Models\Location::withoutGlobalScopes()->find($g->location_id)) ? ($lang === 'en' ? $l->name_en : $l->name_ar) : '—') : '—',
            ':department' => $g->department_id ? (($d = \App\Models\Department::find($g->department_id)) ? ($lang === 'en' ? $d->name_en : $d->name_ar) : '—') : '—',
            ':supervisor' => $sup ? ($lang === 'en' ? $sup->name_en : $sup->name) : '—', ':from' => $g->starts_on->toDateString(), ':to' => $g->ends_on->toDateString(), ':hours' => (string) $g->weekly_hours,
            ':relation' => $lang === 'en' ? $rel[1] : $rel[0], ':tasks' => $g->tasks ?: $g->title, ':cap' => $g->expense_cap ? number_format($g->expense_cap).' '.$g->currency : '—'];
        $vars[':project'] = $g->project ?: '—';
        // FR-VL-004: قالب حسب نوع العامل (متطوع، متدرب، عقد تجريبي، مؤقت، استشاري) وإلا قالب التطوع
        $tpl = ($c['agreements'][$x->kind][$lang] ?? null) ?: ($c['agreement_'.$lang] ?? '');
        return strtr($tpl, $vars);
    }

    /** POL-VL-01/05: التوقيع على الاتفاقية والتعهدات الإلزامية ← «فعّال» */
    public function sign(Engagement $g, User $u, array $v): Engagement
    {
        $x = External::find($g->external_id);
        $self = $x->user_id && $x->user_id === $u->id;
        if (! $self && ! self::canManage($u)) abort(403);
        if ($g->status !== 'approved') throw new ApiException(L::t('الاتفاقية ليست بانتظار التوقيع.', 'The agreement is not awaiting signature.'), 'not_ready', 409);
        $need = self::cfg()['pledges'];
        $given = array_values(array_intersect($need, (array) ($v['pledges'] ?? [])));
        if (count($given) < count($need)) throw ApiException::field('pledges', L::t('يجب الموافقة على كل التعهدات المطلوبة.', 'All required pledges must be accepted.'), 'pledges_required');
        $file = null;
        if (! empty($v['signature'])) $file = Signature::store($v['signature'])->id;
        elseif (! empty($v['file_id'])) $file = (int) $v['file_id'];
        if (! $file) throw ApiException::field('signature', L::t('التوقيع مطلوب.', 'A signature is required.'), 'required');
        $g->update(['pledges' => array_fill_keys($need, now()->toIso8601String()), 'signature_file_id' => $file, 'signed_at' => now(), 'status' => 'active']);
        $x->update(['status' => 'active', 'last_engaged_at' => now()]);
        Audit::record('engagement.signed', $g, ['paper' => ! $self]);
        if ($g->supervisor_id && ($su = User::where('employee_id', $g->supervisor_id)->value('id'))) Notify::user($su, "بدأ {$x->name} تكليفه تحت إشرافك", "{$x->name} started under your supervision", 'externals:'.$x->id);
        return $g->fresh();
    }

    public function checklist(Engagement $g, int $i, bool $done, User $u): Engagement
    {
        if (! self::canManage($u) && ! self::isSupervisor($u, $g)) abort(403);
        $list = $g->onboarding ?? []; if (! isset($list[$i])) abort(404);
        $list[$i]['done'] = $done; $g->update(['onboarding' => $list]);
        return $g;
    }

    public static function isSupervisor(User $u, Engagement $g): bool { return $u->employee_id && $u->employee_id === $g->supervisor_id; }

    // ———————————————— الساعات ————————————————

    /** @return array{log: VolunteerLog, warning: ?string} */
    public function log(Engagement $g, array $v, User $u): array
    {
        $x = External::find($g->external_id);
        $self = $x->user_id === $u->id; $sup = self::isSupervisor($u, $g);
        if (! $self && ! $sup && ! self::canManage($u)) abort(403);
        if ($g->status !== 'active') throw new ApiException(L::t('لا تُسجَّل ساعات قبل توقيع الاتفاقية أو بعد انتهائها.', 'Hours cannot be logged before the agreement is signed or after it ends.'), 'not_active', 409);
        $date = Carbon::parse($v['date'])->toDateString();
        if ($date > now()->toDateString()) throw ApiException::field('date', L::t('لا يُسجَّل يوم لم يأتِ.', 'A future day cannot be logged.'), 'future');
        if ($date < $g->starts_on->toDateString() || $date > $g->ends_on->toDateString()) throw ApiException::field('date', L::t('اليوم خارج مدة الاتفاقية.', 'The day is outside the agreement period.'), 'out_of_period');
        $h = (float) $v['hours'];
        $week = Carbon::parse($date)->startOfWeek(Carbon::SATURDAY);
        $sum = (float) VolunteerLog::where('engagement_id', $g->id)->where('status', '!=', 'rejected')->whereBetween('date', [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])->sum('hours');
        $max = (float) ($g->weekly_hours ?: self::cfg()['max_weekly_hours']);
        if ($x->kind === 'volunteer' && $sum + $h > $max) throw ApiException::field('hours', L::t("تجاوز الحد الأسبوعي ({$max} ساعة؛ المسجّل {$sum}).", "Over the weekly limit ({$max} h; logged {$sum})."), 'weekly_limit');
        $log = VolunteerLog::create(['engagement_id' => $g->id, 'date' => $date, 'hours' => $h, 'activity' => $v['activity'], 'via' => $sup || ! $self ? 'supervisor' : 'portal',
            'status' => $sup ? 'approved' : 'pending', 'approved_by' => $sup ? $u->id : null, 'approved_at' => $sup ? now() : null, 'created_by' => $u->id]);
        $warn = ($sum + $h) >= $max * (int) self::cfg()['warn_pct'] / 100 ? L::t("بلغت ".($sum + $h)." من {$max} ساعة هذا الأسبوع.", "You reached ".($sum + $h)." of {$max} hours this week.") : null;
        if ($self && $g->supervisor_id && ($su = User::where('employee_id', $g->supervisor_id)->value('id'))) Notify::user($su, "ساعات تطوع بانتظار مصادقتك: {$x->name}", "Volunteer hours await your approval: {$x->name}", 'externals:'.$x->id);
        return ['log' => $log, 'warning' => $warn];
    }

    public function approveLogs(array $ids, bool $approve, ?string $note, User $u): int
    {
        $n = 0;
        foreach (VolunteerLog::with('engagement')->whereIn('id', $ids)->where('status', 'pending')->get() as $l) {
            if (! self::isSupervisor($u, $l->engagement) && ! self::canManage($u)) continue;
            $l->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $u->id, 'approved_at' => now(), 'note' => $note]);
            $n++;
        }
        return $n;
    }

    /** POL-VL-03: تعويض مصاريف فعلية موثقة بحد أعلى ← طلب دفع للمالية (لا الرواتب) */
    public function expense(Engagement $g, array $v, User $u): PaymentRequest
    {
        $x = External::find($g->external_id);
        if ($x->user_id !== $u->id && ! self::isSupervisor($u, $g) && ! self::canManage($u)) abort(403);
        if ($g->fee_model !== 'expenses') throw new ApiException(L::t('الاتفاقية لا تتضمن تعويض مصاريف.', 'This agreement has no expense reimbursement.'), 'no_expenses', 409);
        if ($g->status !== 'active') throw new ApiException(L::t('الاتفاقية غير فعّالة.', 'The agreement is not active.'), 'not_active', 409);
        $cap = (float) ($g->expense_cap ?: self::cfg()['expense_cap']);
        if ($cap > 0 && (float) $v['amount'] > $cap) throw ApiException::field('amount', L::t('يتجاوز حد التعويض ('.number_format($cap).' '.$g->currency.').', 'Over the reimbursement cap ('.number_format($cap).' '.$g->currency.').'), 'over_cap');
        if (empty($v['file_id'])) throw ApiException::field('file_id', L::t('أرفق الفاتورة.', 'Attach the receipt.'), 'required');
        $p = Payments::create($x, 'expense', $g->id, $v['description'], (float) $v['amount'], $g->currency, (int) $v['file_id'], $u);
        return $p;
    }

    // ———————————————— الإغلاق والشهادة ————————————————

    public function end(Engagement $g, User $u, array $v, bool $early = false): Engagement
    {
        if (! self::canManage($u) && ! self::isSupervisor($u, $g)) abort(403);
        if ($g->status !== 'active') throw new ApiException(L::t('التكليف غير فعّال.', 'Not active.'), 'not_active', 409);
        $rating = (int) ($v['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) throw ApiException::field('rating', L::t('تقييم المشرف من 1 إلى 5.', 'Supervisor rating 1 to 5.'), 'required');
        // BR-15: شهادة الستاج تتطلب ساعات منجزة ≥ نسبة من المطلوبة وتقييم مشرف ≥ الحد الأدنى
        $x = External::find($g->external_id); $cert = true; $why = null;
        if ($x->kind === 'intern' && $g->required_hours) {
            $td = \App\Services\Training\Td::cfg();
            if (self::hours($g) < $g->required_hours * (int) $td['stage_hours_pct'] / 100) { $cert = false; $why = 'hours'; }
            elseif ($rating < (int) $td['stage_min_rating']) { $cert = false; $why = 'rating'; }
        }
        DB::transaction(function () use ($g, $u, $v, $rating, $early, $x, $cert, $why) {
            $g->update(['status' => $early ? 'terminated' : 'ended', 'ended_at' => now(), 'evaluation' => ['rating' => $rating, 'note' => $v['note'] ?? null, 'at' => now()->toIso8601String(), 'by' => $u->displayName('ar'), 'no_certificate' => $why],
                'stage_eval' => $x->kind === 'intern' ? ['skills' => $v['skills'] ?? [], 'recommend_hire' => (bool) ($v['recommend_hire'] ?? false)] : null,
                'certificate_no' => $cert ? ($x->kind === 'intern' ? 'STG-' : 'VOL-').now()->year.'-'.str_pad((string) Counters::next(($x->kind === 'intern' ? 'stg' : 'vol').'-cert-'.now()->year), 4, '0', STR_PAD_LEFT) : null,
                'verify_code' => $cert ? strtoupper(Str::random(10)) : null]);
            VolunteerLog::where('engagement_id', $g->id)->where('status', 'pending')->update(['status' => 'rejected', 'note' => 'closed']);
            $this->close(External::find($g->external_id));
        });
        Audit::record('engagement.ended', $g, ['early' => $early]);
        return $g->fresh();
    }

    /** FR-VL-015: إنهاء ← تعطيل الحساب، ويبقى في القاعدة لفرص لاحقة */
    private function close(External $x): void
    {
        $x->update(['status' => 'ended']);
        if ($x->user_id) User::whereKey($x->user_id)->update(['active' => false]);
        \App\Services\Attendance\NfcGate::disableExternal($x->id, 'انتهاء الاتفاقية');   // FR-VL-015: البطاقة مع الحساب
    }

    public static function hours(Engagement $g): float { return (float) VolunteerLog::where('engagement_id', $g->id)->where('status', 'approved')->sum('hours'); }

    public static function certificate(Engagement $g): array
    {
        $x = External::find($g->external_id); $c = \App\Models\Company::find($g->company_id);
        return ['no' => $g->certificate_no, 'code' => $g->verify_code, 'kind' => $x->kind, 'stations' => $g->stations, 'name' => ['ar' => $x->name, 'en' => $x->name_en ?: $x->name], 'title' => $g->title, 'tasks' => $g->tasks,
            'from' => $g->starts_on->toDateString(), 'to' => ($g->ended_at ?? $g->ends_on)->toDateString(), 'hours' => self::hours($g), 'company' => ['ar' => $c->name_ar, 'en' => $c->name_en]];
    }

    /** التحقق العام من شهادة تطوع برمزها */
    public static function verify(string $code): ?array
    {
        $g = Engagement::allCompanies()->where('verify_code', strtoupper(trim($code)))->first();
        return $g ? \App\Support\Tenant::run($g->company_id, fn () => self::certificate($g)) : null;
    }

    /** التذكير قبل الانتهاء، وإنهاء المنتهية تلقائياً (FR-VL-015) */
    public function sweep(): int
    {
        $n = 0; $days = (int) self::cfg()['end_warn_days'];
        foreach (Engagement::with('external')->where('status', 'active')->whereNull('end_warned_at')->where('ends_on', '<=', now()->addDays($days)->toDateString())->get() as $g) {
            $g->update(['end_warned_at' => now()]);
            Notify::hr("ينتهي تكليف {$g->external->name} في {$g->ends_on->toDateString()}", "{$g->external->name}'s engagement ends on {$g->ends_on->toDateString()}", 'externals:'.$g->external_id);
            if ($g->external->user_id) Notify::user($g->external->user_id, 'تقترب نهاية اتفاقيتك في '.$g->ends_on->toDateString(), 'Your agreement ends on '.$g->ends_on->toDateString(), 'ext');
            $n++;
        }
        foreach (Engagement::with('external')->where('status', 'active')->where('ends_on', '<', now()->toDateString())->get() as $g) {
            $g->update(['status' => 'ended', 'ended_at' => now(), 'certificate_no' => 'VOL-'.now()->year.'-'.str_pad((string) Counters::next('vol-cert-'.now()->year), 4, '0', STR_PAD_LEFT), 'verify_code' => strtoupper(Str::random(10))]);
            VolunteerLog::where('engagement_id', $g->id)->where('status', 'pending')->update(['status' => 'rejected', 'note' => 'closed']);
            $this->close($g->external);
            Notify::hr("انتهى تكليف {$g->external->name} — أضف تقييم المشرف", "{$g->external->name}'s engagement ended — add the supervisor rating", 'externals:'.$g->external_id);
            $n++;
        }
        return $n + app(Roster::class)->sweep();
    }

    /** FR-VL-013: تحويل إلى متقدم على وظيفة مع نقل بياناته (يبقى سجله وتاريخه هنا) */
    public function toCandidate(External $x, int $jobId, User $u): \App\Models\Candidate
    {
        $job = \App\Models\JobOpening::findOrFail($jobId);
        if ($x->converted_candidate_id) throw new ApiException(L::t('حُوّل هذا الشخص من قبل.', 'Already converted.'), 'converted', 409);
        if (! $x->email) throw ApiException::field('email', L::t('البريد مطلوب للتحويل.', 'An email is required to convert.'), 'required');
        $c = \App\Models\Candidate::create(['ref' => Counters::candidateRef(true), 'job_opening_id' => $job->id, 'stage' => 'new', 'stage_at' => now()->toDateString(), 'name' => $x->name, 'name_en' => $x->name_en ?: $x->name,
            'gender' => $x->gender ?: 'm', 'email' => $x->email, 'mobile' => $x->phone ?: '-', 'education' => [], 'experience' => [], 'refs' => [], 'source' => 'internal', 'years_exp' => (int) $x->years,
            'cv_file_id' => $x->cv_file_id, 'national_id' => $x->national_id, 'dob' => $x->dob?->toDateString()]);
        $c->log("محوَّل من سجل غير الموظفين ({$x->ref}) بواسطة {$u->displayName('ar')}", "Converted from the non-employee register ({$x->ref}) by {$u->displayName('en')}", 'info');
        $x->update(['converted_candidate_id' => $c->id]);
        Audit::record('external.converted', $x, ['candidate' => $c->id]);
        return $c;
    }

    /** صف صندوق الاعتمادات الموحد */
    public static function inboxRow(Engagement $g, User $viewer): array
    {
        $g->loadMissing('external');
        $cur = Workflow::current($g); $x = $g->external;
        $rel = ['replaces' => ['يحل محل موظف', 'Replaces an employee'], 'alongside' => ['إلى جانب موظف', 'Alongside an employee'], 'independent' => ['مهمة مستقلة', 'Independent task']][$g->relation];
        return ['kind' => 'engagement', 'id' => $g->id, 'type' => 'engagement', 'type_name' => ['ar' => 'تكليف غير موظف', 'en' => 'Non-employee engagement'],
            'employee' => ['id' => 0, 'code' => $x->ref, 'name' => ['ar' => $x->name, 'en' => $x->name_en ?: $x->name], 'title' => ['ar' => $x->category?->name_ar ?? '', 'en' => $x->category?->name_en ?? ''], 'dept' => null],
            'from' => $g->starts_on->toDateString(), 'to' => $g->ends_on->toDateString(), 'status' => $g->status, 'reason' => $g->title.($g->justification ? ' — '.$g->justification : ''),
            'engagement' => ['id' => $g->id, 'ref' => $g->ref, 'external_id' => $x->id, 'weekly_hours' => $g->weekly_hours, 'relation' => ['ar' => $rel[0], 'en' => $rel[1]], 'score' => $g->relation_score, 'high' => self::high($g)],
            'warnings' => self::high($g) ? [['key' => 'relation', 'ar' => 'العلاقة أقرب إلى علاقة عمل — راجعها قبل الاعتماد', 'en' => 'Looks like an employment relationship — review before approving']] : [],
            'requested_by' => null, 'created_at' => $g->created_at?->toIso8601String(), 'steps' => Workflow::steps($g), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $g) : false, 'can_cancel' => false];
    }
}
