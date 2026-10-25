<?php

namespace App\Services\Referrals;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\InternalApplication;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\People\Directory;
use App\Services\Recruitment\MatchBands;
use App\Services\Recruitment\MatchScore;
use App\Services\Workflow\Workflow;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الاستقطاب الداخلي F-27 (D-22، SRS §10.9): «ترشيح نفسي» على شاغر مفتوح ← فحص الأهلية من قواعد التوظيف
 * (مدة الخدمة، خارج التجربة، بلا جزاء خلال أشهر) ← المدير المباشر فقط بالمسار الموحد (سلسلة internal) — لا يظهر لـ HR قبله ←
 * عند القبول يصبح متقدماً داخلياً في «فرز» بمسار التوظيف؛ والتعيين يحدّث ملف الموظف نفسه (لا موظف جديد).
 */
class InternalMobility
{
    public function __construct(private Workflow $flow) {}

    /** @return array{ok: bool, reasons: array<int, array{ar: string, en: string}>} */
    public static function eligibility(Employee $e): array
    {
        $min = (int) Settings::rule('internal_min_months');
        $clean = (int) Settings::rule('internal_clean_months');
        $out = [];
        $months = $e->hire_date ? (int) floor($e->hire_date->diffInMonths(now())) : 0;
        if ($months < $min) $out[] = ['ar' => "مدة خدمتك {$months} شهراً — المطلوب {$min} شهراً على الأقل", 'en' => "You have {$months} months of service — {$min} required"];
        if (Settings::rule('internal_no_probation') && in_array($e->status, ['onboarding', 'probation'], true)) $out[] = ['ar' => 'ما زلت في فترة التجربة', 'en' => 'You are still on probation'];
        if (in_array($e->status, ['resigning', 'terminated'], true)) $out[] = ['ar' => 'لديك طلب خروج', 'en' => 'You have an exit in progress'];
        if ($clean > 0 && DisciplineCase::where('employee_id', $e->id)->where('status', 'decided')->where('decided_at', '>=', now()->subMonths($clean))->exists()) {
            $out[] = ['ar' => "صدر بحقك جزاء خلال آخر {$clean} أشهر", 'en' => "A penalty was issued in the last {$clean} months"];
        }
        return ['ok' => ! $out, 'reasons' => $out];
    }

    /** الشواغر المفتوحة في الشركة مع حالة طلبي لكل منها */
    public static function jobs(Employee $e): array
    {
        $mine = InternalApplication::where('employee_id', $e->id)->get()->groupBy('job_opening_id');
        return JobOpening::with('department')->where('status', 'open')->orderByDesc('id')->get()->map(function ($j) use ($e, $mine) {
            $last = $mine->get($j->id)?->sortByDesc('id')->first();
            return ['id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en], 'dept' => $j->department ? ['ar' => $j->department->name_ar, 'en' => $j->department->name_en] : null,
                'visibility' => $j->visibility, 'hard_to_fill' => (bool) $j->hard_to_fill, 'own_dept' => $j->department_id === $e->department_id,
                'application' => $last ? ['id' => $last->id, 'status' => $last->status] : null];
        })->all();
    }

    public function apply(Employee $e, JobOpening $j, string $reason, User $by): InternalApplication
    {
        if ($j->status !== 'open') throw new ApiException(L::t('هذا الشاغر مغلق.', 'This opening is closed.'), 'job_closed', 409);
        $el = self::eligibility($e);
        if (! $el['ok']) throw new ApiException(L::t('لا تنطبق شروط الترشيح الداخلي: ', 'Not eligible: ').implode('، ', array_map(fn ($x) => L::t($x['ar'], $x['en']), $el['reasons'])), 'not_eligible', 422);
        if (InternalApplication::where('employee_id', $e->id)->where('job_opening_id', $j->id)->whereNotIn('status', ['rejected', 'cancelled'])->exists()
            || Candidate::where('internal_employee_id', $e->id)->where('job_opening_id', $j->id)->where('stage', '!=', 'rejected')->exists()) {
            throw new ApiException(L::t('قدّمت طلباً داخلياً لهذا الشاغر.', 'You already applied internally for this opening.'), 'already_applied', 409);
        }
        return DB::transaction(function () use ($e, $j, $reason, $by) {
            $ia = InternalApplication::create(['employee_id' => $e->id, 'job_opening_id' => $j->id, 'reason' => trim($reason), 'status' => 'pending_manager', 'requested_by' => $by->id]);
            $e->log("طلب داخلي F-27 على {$j->title_ar}", "Internal application F-27 for {$j->title_en}", 'info', 'F-27');
            $this->flow->start($ia, $e, Workflow::chain('internal'), $by);
            // بلا مدير مباشر: يُتخطى المستوى فيُقبل مباشرة
            if ($ia->refresh()->status === 'approved') $this->toCandidate($ia);
            return $ia->refresh();
        });
    }

    public function decide(InternalApplication $ia, User $u, bool $approve, ?string $note): InternalApplication
    {
        return $this->flow->decide($ia, $u, $approve, $note, false, fn (InternalApplication $x) => $this->toCandidate($x));
    }

    /** القبول: متقدم داخلي في «فرز» ببيانات الموظف (مثل applyInternal في الديمو) */
    private function toCandidate(InternalApplication $ia): void
    {
        $e = Employee::with('department')->findOrFail($ia->employee_id);
        $j = JobOpening::findOrFail($ia->job_opening_id);
        $years = (int) floor(Directory::tenureDays($e) / 365) + 2;
        $c = Candidate::create(['ref' => Counters::candidateRef(true), 'job_opening_id' => $j->id, 'stage' => 'screening', 'stage_at' => now()->toDateString(),
            'name' => $e->name, 'name_en' => $e->name_en, 'gender' => $e->gender ?: 'M', 'dob' => $e->dob, 'national_id' => $e->national_id, 'email' => $e->email, 'mobile' => $e->phone ?: '—',
            'education' => [['phase' => 'bachelor', 'institute' => '', 'major' => '', 'year' => '', 'grade' => '']],
            'experience' => [['company' => \App\Support\Settings::company('name_ar').' — نقل/ترقية داخلية', 'position' => $e->title_ar, 'from' => $e->hire_date?->toDateString(), 'to' => null, 'tasks' => '', 'reason' => '']],
            'refs' => [], 'english' => 'good', 'salary' => (float) $e->salary, 'source' => 'internal', 'years_exp' => $years, 'degree_level' => 3,
            'internal_employee_id' => $e->id, 'internal_reason' => $ia->reason, 'user_id' => User::where('employee_id', $e->id)->value('id'), 'keep_cv' => false]);
        $score = MatchScore::total($c, $j);
        $c->update(['match_score' => $score, 'match_band' => MatchBands::of($score)]);
        $c->log("طلب داخلي من {$e->name} ({$e->code}) — قبله المدير المباشر", "Internal application from {$e->name_en} ({$e->code}) — approved by the manager", 'info', 'F-27');
        $ia->update(['candidate_id' => $c->id]);
        Notify::hr("طلب داخلي جديد على {$j->title_ar} من {$e->name} — قبله مديره", "New internal application for {$j->title_en} from {$e->name_en}", 'cand:'.$c->id);
    }

    public static function row(InternalApplication $ia, ?User $viewer = null): array
    {
        $cur = Workflow::current($ia);
        return ['kind' => 'internal', 'id' => $ia->id, 'type' => 'internal', 'type_name' => ['ar' => $ia->workflowLabel('ar'), 'en' => $ia->workflowLabel('en')],
            'employee' => $ia->employee ? ['id' => $ia->employee->id, 'code' => $ia->employee->code, 'name' => ['ar' => $ia->employee->name, 'en' => $ia->employee->name_en], 'title' => ['ar' => $ia->employee->title_ar, 'en' => $ia->employee->title_en]] : null,
            'job' => $ia->jobOpening ? ['id' => $ia->jobOpening->id, 'code' => $ia->jobOpening->code, 'title' => ['ar' => $ia->jobOpening->title_ar, 'en' => $ia->jobOpening->title_en]] : null,
            'from' => $ia->created_at?->toDateString(), 'to' => $ia->created_at?->toDateString(), 'reason' => $ia->reason, 'status' => $ia->status, 'warnings' => [],
            'candidate_stage' => $ia->candidate?->stage, 'created_at' => $ia->created_at?->toIso8601String(), 'steps' => Workflow::steps($ia), 'step_id' => $cur?->id,
            'can_act' => $viewer && $cur ? Workflow::canAct($viewer, $cur, $ia) : false,
            'can_cancel' => $viewer && $cur && $viewer->employee_id === $ia->employee_id];
    }
}
