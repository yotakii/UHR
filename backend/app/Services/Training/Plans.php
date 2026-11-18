<?php

namespace App\Services\Training;

use App\Exceptions\ApiException;
use App\Models\TdCbp;
use App\Models\TdNeed;
use App\Models\TdPlan;
use App\Models\TdPlanItem;
use App\Models\TdSession;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * الخطة السنوية F-04 (WF-02): بنود من الاحتياجات المعتمدة أو يدوية، موازنة إجمالية وموزعة مع فحوص آلية،
 * سير اعتماد (الموارد البشرية ← المالية استشارة ← الإدارة العليا)، قفل النسخة الأساسية والتعديل بطلب تغيير، والمخطط مقابل المنفذ.
 * وخطة بناء القدرات CBP: أهداف ← قدرات ← فجوة محسوبة ← مبادرات تُدرج في الخطة.
 */
class Plans
{
    public const DEFAULT_CHAIN = [['role_ar' => 'مدير الموارد البشرية', 'role_en' => 'HR director', 'role_key' => 'hr_director'], ['role_ar' => 'المالية (استشارة)', 'role_en' => 'Finance (consulted)', 'role_key' => 'finance'],
        ['role_ar' => 'الرئيس التنفيذي', 'role_en' => 'CEO', 'role_key' => 'ceo']];

    public function __construct(private Workflow $wf) {}
    private static function min(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    public function save(array $v, User $u, ?TdPlan $p = null): TdPlan
    {
        if (! Td::manages($u)) abort(403);
        if ($p && ! in_array($p->status, ['draft', 'rejected', 'approved'], true)) throw new ApiException(L::t('الخطة قيد الاعتماد.', 'The plan is under approval.'), 'locked', 409);
        $data = ['year' => $v['year'], 'title' => $v['title'], 'scope' => $v['scope'] ?? 'company', 'location_id' => $v['location_id'] ?? null, 'budget' => (float) $v['budget'], 'currency' => $v['currency'] ?? 'SYP', 'budget_split' => $v['budget_split'] ?? null];
        if ($p) { $p->update($data); return $p; }
        if (TdPlan::where('year', $data['year'])->where('scope', $data['scope'])->where('location_id', $data['location_id'])->exists()) throw ApiException::field('year', L::t('توجد خطة لهذه السنة والنطاق.', 'A plan exists for this year and scope.'), 'duplicate');
        $p = TdPlan::create($data + ['status' => 'draft', 'requested_by' => $u->id]);
        if (! empty($v['copy_from'])) $this->copy(TdPlan::findOrFail($v['copy_from']), $p);
        return $p;
    }

    /** FR-TD-049: نسخ خطة سنة سابقة كنقطة بداية */
    private function copy(TdPlan $from, TdPlan $to): void
    {
        foreach ($from->items()->whereNotIn('status', ['cancelled'])->get() as $i)
            TdPlanItem::create(array_intersect_key($i->getAttributes(), array_flip(['program_id', 'title', 'path', 'delivery', 'audience', 'location_id', 'department_id', 'month', 'trainees', 'estimated_cost', 'priority', 'objective'])) + ['plan_id' => $to->id, 'status' => 'planned']);
    }

    /** بند خطة — بعد الاعتماد كل تغيير يُسجَّل طلب تعديل بأثره على الموازنة (FR-TD-044 / BR-18) */
    public function item(TdPlan $p, array $v, User $u, ?TdPlanItem $i = null): ?TdPlanItem
    {
        if (! Td::manages($u)) abort(403);
        if (in_array($p->status, ['pending_hr', 'pending_exec'], true)) throw new ApiException(L::t('الخطة قيد الاعتماد.', 'The plan is under approval.'), 'locked', 409);
        $data = array_intersect_key($v, array_flip(['program_id', 'title', 'path', 'delivery', 'audience', 'location_id', 'department_id', 'month', 'trainees', 'estimated_cost', 'priority', 'objective', 'status']));
        if ($p->status === 'approved') {
            if (mb_strlen(trim((string) ($v['change_reason'] ?? ''))) < self::min()) throw ApiException::field('change_reason', L::t('الخطة معتمدة — اكتب سبب التعديل.', 'The plan is approved — write the reason for the change.'), 'reason_required');
            $delta = (float) ($data['estimated_cost'] ?? $i?->estimated_cost ?? 0) - (float) ($i?->estimated_cost ?? 0);
            $ch = $i?->changes ?? [];
            $entry = ['at' => now()->toIso8601String(), 'by' => $u->displayName('ar'), 'what' => $i ? ($data['status'] ?? 'edit') : 'add', 'reason' => $v['change_reason'], 'cost_delta' => $delta];
            // BR-18: التعديل لا يُطبَّق قبل اعتماد طلب التغيير (حسب الإعداد)
            if (Td::cfg()['change_approval']) {
                $a = app(TdExtras::class)->requestChange($p, $i, $data, $v['change_reason'], $delta, $u);
                if ($i && $a->status !== 'approved') { $i->update(['changes' => array_merge($ch, [$entry + ['status' => 'pending', 'approval_id' => $a->id]])]); return $i->fresh(); }
                if (! $i) return ($id = $a->fresh()->item_id) ? TdPlanItem::find($id) : null;
                return $i->fresh();
            }
            $ch[] = $entry;
            $data['changes'] = $ch;
        }
        if ($i) { $i->update($data); } else { $i = TdPlanItem::create($data + ['plan_id' => $p->id, 'status' => 'planned']); }
        if (! empty($v['need_ids'])) TdNeed::whereIn('id', $v['need_ids'])->where('status', 'approved')->update(['status' => 'planned', 'plan_item_id' => $i->id]);
        return $i->fresh();
    }

    /** FR-TD-042/037: فحوص آلية قبل الرفع — الإجمالي مقابل السقف، مواسم الذروة، غياب هدف مرتبط */
    public static function checks(TdPlan $p): array
    {
        $items = $p->items()->whereNotIn('status', ['cancelled'])->get();
        $out = [];
        $sum = (float) $items->sum('estimated_cost');
        if ($p->budget > 0 && $sum > $p->budget) $out[] = ['key' => 'over_budget', 'ar' => 'مجموع البنود يتجاوز الموازنة', 'en' => 'Items exceed the budget', 'value' => $sum];
        $noGoal = $items->filter(fn ($i) => ! $i->objective)->count();
        if ($noGoal) $out[] = ['key' => 'no_objective', 'ar' => "{$noGoal} بند دون هدف مرتبط", 'en' => "{$noGoal} item(s) without a linked objective", 'value' => $noGoal];
        $peak = collect(Td::cfg()['peak_seasons'])->flatMap(fn ($s) => range((int) substr($s['from'] ?? '01-01', 0, 2), (int) substr($s['to'] ?? '01-01', 0, 2)))->unique();
        $inPeak = $items->filter(fn ($i) => $i->month && $peak->contains($i->month))->count();
        if ($inPeak) $out[] = ['key' => 'peak', 'ar' => "{$inPeak} بند في موسم ذروة", 'en' => "{$inPeak} item(s) in a peak season", 'value' => $inPeak];
        // الموازنة الموزعة: أرباع Q1..Q4، أو إدارات dept:{id}، أو مسارات path:{key}
        foreach ((array) ($p->budget_split ?? []) as $k => $cap) {
            $q = str_starts_with($k, 'dept:') ? $items->where('department_id', (int) substr($k, 5))->sum('estimated_cost')
                : (str_starts_with($k, 'path:') ? $items->where('path', substr($k, 5))->sum('estimated_cost')
                : $items->filter(fn ($i) => $i->month && 'Q'.(int) ceil($i->month / 3) === $k)->sum('estimated_cost'));
            if ((float) $cap > 0 && $q > (float) $cap) $out[] = ['key' => 'over_'.$k, 'ar' => "الربع {$k} يتجاوز موازنته", 'en' => "{$k} exceeds its budget", 'value' => $q];
        }
        return $out;
    }

    public function submit(TdPlan $p, User $u): TdPlan
    {
        if (! Td::manages($u)) abort(403);
        if (! in_array($p->status, ['draft', 'rejected'], true)) throw new ApiException(L::t('رُفعت الخطة من قبل.', 'Already submitted.'), 'locked', 409);
        if (! $p->items()->exists()) throw new ApiException(L::t('أضف بنوداً للخطة أولاً.', 'Add items first.'), 'empty', 422);
        \App\Models\RequestStep::where('subject_type', $p->getMorphClass())->where('subject_id', $p->id)->delete();
        $this->wf->start($p, null, Workflow::chainFor('td_plan', ['amount' => $p->budget, 'currency' => $p->currency, 'branch' => $p->location_id]), $u);
        $p->refresh();
        if ($p->status === 'approved') $this->approved($p);
        return $p;
    }

    public function decide(TdPlan $p, User $u, bool $approve, ?string $note): TdPlan
    {
        $cur = Workflow::current($p);
        if ($cur && $cur->role_key === 'finance' && $note) $p->update(['finance_note' => $note]);
        return $this->wf->decide($p, $u, $approve, $note, false, fn (TdPlan $x) => $this->approved($x))->fresh();
    }

    /** نشر الخطة: قفل النسخة الأساسية، وإنشاء مهام تحضير لكل بند (10.3)، وإشعار المالية */
    private function approved(TdPlan $p): void
    {
        $items = $p->items()->get();
        $p->update(['baseline' => ['at' => now()->toIso8601String(), 'budget' => $p->budget, 'items' => $items->map(fn ($i) => array_intersect_key($i->getAttributes(), array_flip(['id', 'title', 'month', 'trainees', 'estimated_cost', 'path', 'delivery'])))->values()->all()]]);
        $by = \App\Models\User::find($p->requested_by);
        if ($by?->employee_id) foreach ($items as $i) if ($i->month) {
            $due = now()->setDate($p->year, $i->month, 1)->subDays(14);
            if ($due->isFuture()) \App\Models\WorkTask::create(['title' => "تحضير: {$i->title}", 'description' => 'مهمة تحضير من الخطة السنوية للتدريب '.$p->year, 'employee_id' => $by->employee_id, 'created_by' => $by->id,
                'priority' => 'normal', 'status' => 'todo', 'progress' => 0, 'due_on' => $due->toDateString()]);
        }
        Notify::hr("اعتُمدت خطة التدريب {$p->year}", "Training plan {$p->year} approved", 'training:plan');
        Audit::record('td_plan.approved', $p);
    }

    /** FR-TD-045: المخطط مقابل المنفذ بالعدد والساعات والتكلفة */
    public static function progress(TdPlan $p): array
    {
        $items = $p->items()->get();
        $sessions = TdSession::whereIn('plan_item_id', $items->pluck('id'))->with('program:id,hours')->get();
        $done = $sessions->whereIn('status', ['completed', 'closed']);
        $actual = (float) $done->sum(fn ($s) => collect($s->costs ?? [])->sum(fn ($c) => Td::convert((float) ($c['amount'] ?? 0), $c['currency'] ?? $s->currency, $p->currency)));
        return ['items' => $items->count(), 'done' => $items->where('status', 'done')->count(), 'planned_cost' => (float) $items->whereNotIn('status', ['cancelled'])->sum('estimated_cost'),
            'actual_cost' => round($actual, 2), 'planned_trainees' => (int) $items->sum('trainees'),
            'trained' => \App\Models\TdEnrollment::whereIn('session_id', $done->pluck('id'))->where('result', 'passed')->count(),
            'hours' => (float) $done->sum(fn ($s) => (float) ($s->program?->hours ?? 0)), 'budget' => $p->budget,
            'by_quarter' => collect([1, 2, 3, 4])->mapWithKeys(fn ($q) => ['Q'.$q => (float) $items->filter(fn ($i) => $i->month && (int) ceil($i->month / 3) === $q)->sum('estimated_cost')]),
            'by_path' => $items->groupBy('path')->map(fn ($g) => (float) $g->sum('estimated_cost'))];
    }

    // ———————————————— CBP ————————————————

    public function saveCbp(array $v, User $u, ?TdCbp $c = null): TdCbp
    {
        if (! Td::manages($u)) abort(403);
        $goals = array_map(fn ($g) => $g + ['id' => $g['id'] ?? (string) \Illuminate\Support\Str::uuid(), 'capacities' => $g['capacities'] ?? [], 'initiatives' => array_map(fn ($i) => $i + ['id' => $i['id'] ?? (string) \Illuminate\Support\Str::uuid(), 'progress' => (int) ($i['progress'] ?? 0)], $g['initiatives'] ?? [])], $v['goals'] ?? []);
        $data = ['title' => $v['title'], 'starts_on' => $v['starts_on'] ?? null, 'ends_on' => $v['ends_on'] ?? null, 'owner' => $v['owner'] ?? null, 'description' => $v['description'] ?? null, 'goals' => $goals, 'status' => $v['status'] ?? 'active'];
        if ($c) { $c->update($data); return $c; }
        return TdCbp::create($data);
    }

    /** FR-TD-052: الفجوة = المطلوب − المتوفر (عدد من بلغوا المستوى في الوظيفة) */
    public static function cbpGaps(TdCbp $c): array
    {
        $out = [];
        foreach ($c->goals ?? [] as $g) foreach ($g['capacities'] ?? [] as $k => $cap) {
            $have = \App\Models\EmployeeCompetency::where('competency_id', $cap['competency_id'] ?? 0)->where('level', '>=', (int) ($cap['level'] ?? 1))
                ->whereHas('employee', fn ($q) => $q->current()->when($cap['job_id'] ?? null, fn ($w, $j) => $w->where('job_id', $j)))->count();
            $out[$g['id']][$k] = ['required' => (int) ($cap['count'] ?? 0), 'have' => $have, 'gap' => max(0, (int) ($cap['count'] ?? 0) - $have)];
        }
        return $out;
    }

    /** FR-TD-054: مبادرة تدريبية ← بند في خطة السنة مع الحفاظ على الرابط */
    public function pushInitiative(TdCbp $c, string $goalId, string $initId, TdPlan $p, User $u): ?TdPlanItem
    {
        $goals = $c->goals;
        foreach ($goals as &$g) if ($g['id'] === $goalId) foreach ($g['initiatives'] as &$i) if ($i['id'] === $initId) {
            if (! empty($i['plan_item_id'])) throw new ApiException(L::t('المبادرة مدرجة في الخطة.', 'Already in the plan.'), 'duplicate', 409);
            $item = $this->item($p, ['title' => $i['title'], 'path' => 'development', 'delivery' => 'internal', 'estimated_cost' => (float) ($i['cost'] ?? 0), 'objective' => $g['title'], 'priority' => 'high',
                'change_reason' => $p->status === 'approved' ? 'مبادرة من خطة بناء القدرات: '.$c->title : null], $u);
            if (! $item) { $i['plan_item_id'] = null; $i['plan_change'] = 'pending'; $c->update(['goals' => $goals]); return null; }   // بانتظار اعتماد تعديل الخطة
            $item->update(['cbp_initiative' => $c->id]);
            $i['plan_item_id'] = $item->id;
            $c->update(['goals' => $goals]);
            return $item;
        }
        abort(404);
    }

    /** صف صندوق الاعتمادات الموحد */
    public static function inboxRow(TdPlan $p, User $viewer): array
    {
        $cur = Workflow::current($p); $pr = self::progress($p);
        return ['kind' => 'td_plan', 'id' => $p->id, 'type' => 'td_plan', 'type_name' => ['ar' => 'الخطة السنوية للتدريب', 'en' => 'Annual training plan'], 'employee' => null,
            'from' => $p->year.'-01-01', 'to' => $p->year.'-12-31', 'status' => $p->status, 'reason' => $p->title,
            'td_plan' => ['id' => $p->id, 'year' => $p->year, 'items' => $pr['items'], 'planned_cost' => $pr['planned_cost'], 'budget' => $p->budget, 'currency' => $p->currency, 'checks' => self::checks($p)],
            'warnings' => [], 'requested_by' => null, 'created_at' => $p->created_at?->toIso8601String(), 'steps' => Workflow::steps($p), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $p) : false, 'can_cancel' => false];
    }
}
