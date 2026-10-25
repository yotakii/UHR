<?php

namespace App\Services\Development;

use App\Exceptions\ApiException;
use App\Models\Competency;
use App\Models\DevelopmentPlan;
use App\Models\Employee;
use App\Models\SuccessionCandidate;
use App\Models\SuccessionPlan;
use App\Models\User;
use App\Services\People\Directory;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * خطة التعاقب (D-23): HR يحدد المنصب الحرج والكفاءات ← المدير يرشّح من فريقه بالجاهزية (now / 1y / 3y) ←
 * HR يحدد الفجوات ويربط المرشح بخطة تطوير ← منصب بلا مرشح «جاهز الآن» = تنبيه فجوة ← مراجعة دورية (succession_review_months).
 */
class Succession
{
    public const READINESS = ['now', '1y', '3y'];

    public function __construct(private DevelopmentPlans $plans) {}

    /** إدارة المناصب: نطاق الكل في التعاقب (الموارد البشرية) */
    public static function manages(User $u): bool { return Perm::can($u, 'succession', 'edit') && Perm::scope($u, 'succession') === 'all'; }

    public function save(?SuccessionPlan $p, array $in): SuccessionPlan
    {
        $d = Validator::make($in, ['title_ar' => ['required', 'string', 'min:3', 'max:190'], 'title_en' => ['required', 'string', 'min:2', 'max:190'], 'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'incumbent_id' => ['nullable', 'integer'], 'risk' => ['required', 'in:low,medium,high'], 'reason' => ['nullable', 'string', 'max:2000'],
            'competencies' => ['required', 'array', 'min:1'], 'competencies.*' => ['integer']], [], ['title_ar' => L::t('المنصب', 'Position'), 'competencies' => L::t('الكفاءات المطلوبة', 'Required competencies')])->validate();
        if (Competency::whereIn('id', $d['competencies'])->count() !== count(array_unique($d['competencies']))) throw ApiException::field('competencies', L::t('كفاءة غير موجودة.', 'Unknown competency.'), 'invalid');
        if (! empty($d['incumbent_id']) && ! Employee::current()->whereKey($d['incumbent_id'])->exists()) throw ApiException::field('incumbent_id', L::t('شاغل المنصب غير موجود.', 'Unknown incumbent.'), 'invalid');
        $row = ['title_ar' => trim($d['title_ar']), 'title_en' => trim($d['title_en']), 'department_id' => $d['department_id'] ?? null, 'incumbent_id' => $d['incumbent_id'] ?? null,
            'risk' => $d['risk'], 'reason' => trim((string) ($d['reason'] ?? '')) ?: null, 'competencies' => array_values(array_map('intval', array_unique($d['competencies'])))];
        if ($p) { $p->update($row); return $p; }
        return SuccessionPlan::create($row + ['status' => 'draft', 'created_by' => auth()->id()]);
    }

    public function activate(SuccessionPlan $p): SuccessionPlan
    {
        if ($p->status !== 'draft') throw new ApiException(L::t('الخطة مفعّلة.', 'The plan is already active.'), 'plan_state', 409);
        $p->update(['status' => 'active', 'next_review' => $this->nextReview()]);
        // المديرون المعنيون يرشّحون من فرقهم
        $mgrs = Employee::current()->when($p->department_id, fn ($q) => $q->where('department_id', $p->department_id))->whereIn('id', Employee::current()->whereNotNull('manager_id')->select('manager_id'))->pluck('id');
        foreach (User::where('active', true)->whereIn('employee_id', $mgrs)->pluck('id') as $uid) Notify::user($uid, "خطة تعاقب لمنصب «{$p->title_ar}» — رشّح من فريقك وحدّد جاهزيتهم", "Succession plan for «{$p->title_en}» — nominate from your team", 'development');
        return $p;
    }

    public function review(SuccessionPlan $p, User $by, string $note): SuccessionPlan
    {
        if ($p->status === 'draft') throw new ApiException(L::t('فعّل الخطة أولاً.', 'Activate the plan first.'), 'plan_state', 409);
        if (mb_strlen(trim($note)) < 5) throw ApiException::field('note', L::t('اكتب ملخص المراجعة.', 'Write a review summary.'), 'reason_required');
        $p->update(['status' => 'reviewed', 'reviewed_at' => now(), 'reviewed_by' => $by->id, 'review_note' => trim($note), 'next_review' => $this->nextReview()]);
        return $p;
    }

    /** ترشيح: المدير من فريقه المباشر، والموارد البشرية من الكل؛ إعادة الترشيح تحدّث الجاهزية */
    public function nominate(SuccessionPlan $p, Employee $e, string $readiness, ?string $note, User $by): SuccessionCandidate
    {
        if (! in_array($p->status, ['active', 'reviewed'], true)) throw new ApiException(L::t('الترشيح بعد تفعيل الخطة.', 'Nominations open once the plan is active.'), 'plan_state', 409);
        if (! in_array($readiness, self::READINESS, true)) throw ApiException::field('readiness', L::t('حدّد الجاهزية.', 'Choose the readiness.'), 'invalid');
        if ($e->id === $p->incumbent_id) throw ApiException::field('employee_id', L::t('شاغل المنصب لا يُرشَّح لخلافته.', 'The incumbent cannot succeed himself.'), 'invalid');
        if (! self::manages($by) && ! Directory::scoped($by, 'succession')->whereKey($e->id)->where('id', '!=', $by->employee_id ?? 0)->exists()) {
            throw ApiException::field('employee_id', L::t('رشّح من فريقك المباشر فقط.', 'Nominate from your direct team only.'), 'out_of_scope');
        }
        $c = SuccessionCandidate::updateOrCreate(['succession_plan_id' => $p->id, 'employee_id' => $e->id], ['readiness' => $readiness, 'note' => trim((string) $note) ?: null, 'nominated_by' => $by->id]);
        if (! self::manages($by)) Notify::hr("رشّح {$by->displayName('ar')} {$e->name} لمنصب «{$p->title_ar}» ({$this->label($readiness, 'ar')})", "{$by->displayName('en')} nominated {$e->name_en} for «{$p->title_en}»", 'development');
        if ($readiness === 'now') $p->update(['gap_alerted_at' => null]);
        return $c;
    }

    /** الموارد البشرية: الجاهزية والفجوات، وإنشاء خطة تطوير مرتبطة (مسودة) عند الطلب */
    public function assess(SuccessionCandidate $c, array $in, User $by): SuccessionCandidate
    {
        $d = Validator::make($in, ['readiness' => ['required', 'in:'.implode(',', self::READINESS)], 'gaps' => ['nullable', 'string', 'max:2000'], 'create_plan' => ['nullable', 'boolean']])->validate();
        return DB::transaction(function () use ($c, $d, $by) {
            $c->update(['readiness' => $d['readiness'], 'gaps' => trim((string) ($d['gaps'] ?? '')) ?: null]);
            if (! empty($d['create_plan']) && ! $c->development_plan_id) {
                $p = $c->plan()->firstOrFail();
                if (! $c->gaps) throw ApiException::field('gaps', L::t('حدّد فجوات التطوير قبل إنشاء الخطة.', 'Set the development gaps before creating a plan.'), 'reason_required');
                $plan = $this->plans->save(null, ['employee_id' => $c->employee_id, 'title' => "الاستعداد لمنصب {$p->title_ar}", 'gaps' => $c->gaps,
                    'goals' => [['text' => "سد فجوات الجاهزية لمنصب {$p->title_ar}"]]], $by);
                $plan->update(['source' => 'succession']);
                $c->update(['development_plan_id' => $plan->id]);
            }
            return $c->refresh();
        });
    }

    public function remove(SuccessionCandidate $c, User $by): void
    {
        if (! self::manages($by) && $c->nominated_by !== $by->id) throw new ApiException(L::t('لا تملك سحب هذا الترشيح.', 'You cannot withdraw this nomination.'), 'forbidden', 403);
        $c->delete();
    }

    /** يومياً: منصب فعّال بلا مرشح «جاهز الآن» ← تنبيه فجوة للموارد البشرية مرة واحدة؛ ومراجعة مستحقة */
    public function sweep(): array
    {
        $out = ['gaps' => 0, 'review' => 0];
        foreach (SuccessionPlan::withCount(['candidates as ready' => fn ($q) => $q->where('readiness', 'now')])->whereIn('status', ['active', 'reviewed'])->get() as $p) {
            if ($p->ready === 0 && ! $p->gap_alerted_at) {
                Notify::hr("فجوة تعاقب: منصب «{$p->title_ar}» بلا مرشح جاهز الآن", "Succession gap: «{$p->title_en}» has no ready-now successor", 'development');
                $p->update(['gap_alerted_at' => now()]);
                $out['gaps']++;
            }
            if ($p->next_review && $p->next_review->toDateString() <= now()->toDateString() && $p->status === 'reviewed') {
                $p->update(['status' => 'active']);   // تعود «فعّالة» حتى تُراجَع من جديد
                Notify::hr("حان موعد مراجعة خطة التعاقب «{$p->title_ar}»", "Succession plan «{$p->title_en}» is due for review", 'development');
                $out['review']++;
            }
        }
        return $out;
    }

    private function nextReview(): string { return now()->addMonthsNoOverflow((int) Settings::development()['succession_review_months'])->toDateString(); }

    public function label(string $key, string $lang): string
    {
        $r = collect(Settings::development()['readiness'])->firstWhere('key', $key);
        return $r[$lang] ?? $key;
    }

    public static function row(SuccessionPlan $p, User $viewer): array
    {
        $manage = self::manages($viewer);
        $team = $manage ? null : Directory::scoped($viewer, 'succession')->pluck('id')->all();
        $cands = $p->candidates->filter(fn ($c) => $manage || in_array($c->employee_id, $team, true));
        $comps = Competency::whereIn('id', $p->competencies ?? [])->get();
        return ['id' => $p->id, 'title' => ['ar' => $p->title_ar, 'en' => $p->title_en], 'title_ar' => $p->title_ar, 'title_en' => $p->title_en, 'risk' => $p->risk, 'reason' => $p->reason, 'status' => $p->status,
            'department' => $p->department ? ['id' => $p->department->id, 'ar' => $p->department->name_ar, 'en' => $p->department->name_en] : null, 'department_id' => $p->department_id,
            'incumbent' => $p->incumbent ? ['id' => $p->incumbent->id, 'name' => ['ar' => $p->incumbent->name, 'en' => $p->incumbent->name_en], 'title' => ['ar' => $p->incumbent->title_ar, 'en' => $p->incumbent->title_en]] : null, 'incumbent_id' => $p->incumbent_id,
            'competencies' => $comps->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en])->values(), 'competency_ids' => $p->competencies,
            'next_review' => $p->next_review?->toDateString(), 'reviewed_at' => $p->reviewed_at?->toIso8601String(), 'review_note' => $manage ? $p->review_note : null,
            'gap' => in_array($p->status, ['active', 'reviewed'], true) && ! $p->candidates->contains('readiness', 'now'),
            'counts' => ['now' => $p->candidates->where('readiness', 'now')->count(), '1y' => $p->candidates->where('readiness', '1y')->count(), '3y' => $p->candidates->where('readiness', '3y')->count()],
            'candidates' => $cands->map(fn (SuccessionCandidate $c) => ['id' => $c->id, 'readiness' => $c->readiness, 'note' => $c->note, 'gaps' => $c->gaps,
                'employee' => ['id' => $c->employee->id, 'code' => $c->employee->code, 'name' => ['ar' => $c->employee->name, 'en' => $c->employee->name_en], 'title' => ['ar' => $c->employee->title_ar, 'en' => $c->employee->title_en]],
                'plan' => $c->developmentPlan ? ['id' => $c->developmentPlan->id, 'status' => $c->developmentPlan->status, 'title' => $c->developmentPlan->title] : null,
                'nominated_by' => $c->nominator?->displayName('ar'), 'mine' => $c->nominated_by === $viewer->id])->values()];
    }
}
