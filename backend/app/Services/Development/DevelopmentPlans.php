<?php

namespace App\Services\Development;

use App\Exceptions\ApiException;
use App\Models\DevelopmentActivity;
use App\Models\DevelopmentPlan;
use App\Models\Employee;
use App\Models\User;
use App\Services\People\Directory;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * خطة التطوير الفردية (D-24): الفجوات والأهداف والأنشطة (مسؤول + موعد) ← تفعيل ← متابعة التقدم ← إعادة تقييم دورية
 * (review_months، ربعية افتراضياً) ← اكتمال. النشاط المتأخر ينبّه الموظف ومسؤول النشاط والمدير مرة واحدة.
 */
class DevelopmentPlans
{
    public const KINDS = ['course', 'mentoring', 'project', 'reading', 'rotation', 'other'];

    public function save(?DevelopmentPlan $p, array $in, User $by): DevelopmentPlan
    {
        if ($p && $p->status === 'done') throw new ApiException(L::t('الخطة مكتملة ولا تُعدَّل.', 'A completed plan cannot be edited.'), 'plan_done', 409);
        $d = Validator::make($in, ['scope' => ['nullable', 'in:employee,department,company'], 'department_id' => ['nullable', 'integer'],
            'employee_id' => [$p || in_array($in['scope'] ?? 'employee', ['department', 'company'], true) ? 'nullable' : 'required', 'integer'], 'title' => ['required', 'string', 'min:3', 'max:190'], 'gaps' => ['nullable', 'string', 'max:3000'],
            'goals' => ['required', 'array', 'min:1', 'max:10'], 'goals.*.text' => ['required', 'string', 'min:3', 'max:300'], 'goals.*.done' => ['nullable', 'boolean'],
            'activities' => ['nullable', 'array', 'max:20'], 'activities.*.id' => ['nullable', 'integer'], 'activities.*.title' => ['required', 'string', 'min:3', 'max:190'],
            'activities.*.kind' => ['required', 'in:'.implode(',', self::KINDS)], 'activities.*.owner_employee_id' => ['nullable', 'integer'], 'activities.*.due_on' => ['required', 'date']],
            [], ['title' => L::t('عنوان الخطة', 'Plan title'), 'goals' => L::t('الأهداف', 'Goals'), 'goals.*.text' => L::t('الهدف', 'Goal'), 'activities.*.title' => L::t('النشاط', 'Activity'), 'activities.*.due_on' => L::t('الموعد', 'Due date')])->validate();
        // ملاحظة 69: خطة لإدارة (الموارد البشرية أو رئيس الإدارة) أو للشركة كلها (الموارد البشرية)
        $scope = $p?->scope ?? ($d['scope'] ?? 'employee');
        $empId = $scope === 'employee' ? ($p?->employee_id ?? (int) $d['employee_id']) : null;
        $deptId = $scope === 'department' ? ($p?->department_id ?? (int) ($d['department_id'] ?? 0)) : null;
        if (! $p && $scope === 'employee' && ! Directory::scoped($by, 'succession')->whereKey($empId)->where('id', '!=', $by->employee_id ?? 0)->exists()) {
            throw ApiException::field('employee_id', L::t('الموظف خارج نطاقك (فريقك المباشر).', 'This employee is outside your scope (your direct team).'), 'out_of_scope');
        }
        if (! $p && $scope === 'department') {
            if (! $deptId || ! \App\Models\Department::whereKey($deptId)->exists()) throw ApiException::field('department_id', L::t('اختر الإدارة.', 'Choose the department.'), 'required');
            if (! Succession::manages($by) && ! \App\Models\Department::whereKey($deptId)->where('head_id', $by->employee_id ?? 0)->exists()) throw new ApiException(L::t('خطة الإدارة للموارد البشرية أو لرئيس الإدارة.', 'Department plans belong to HR or the department head.'), 'forbidden', 403);
        }
        if (! $p && $scope === 'company' && ! Succession::manages($by)) throw new ApiException(L::t('خطة الشركة للموارد البشرية.', 'Company plans belong to HR.'), 'forbidden', 403);
        $owners = collect($d['activities'] ?? [])->pluck('owner_employee_id')->filter()->unique();
        if ($owners->isNotEmpty() && Employee::whereIn('id', $owners)->count() !== $owners->count()) throw ApiException::field('activities', L::t('مسؤول نشاط غير موجود.', 'Unknown activity owner.'), 'invalid');
        return DB::transaction(function () use ($p, $d, $by, $empId, $deptId, $scope) {
            $row = ['title' => trim($d['title']), 'gaps' => trim((string) ($d['gaps'] ?? '')) ?: null, 'goals' => array_map(fn ($g) => ['text' => trim($g['text']), 'done' => (bool) ($g['done'] ?? false)], array_values($d['goals']))];
            $p = $p ? tap($p)->update($row) : DevelopmentPlan::create($row + ['scope' => $scope, 'employee_id' => $empId, 'department_id' => $deptId, 'status' => 'draft', 'created_by' => $by->id, 'source' => $d['source'] ?? 'manual']);
            if (array_key_exists('activities', $d)) {
                $keep = [];
                foreach ($d['activities'] ?? [] as $a) {
                    $vals = ['title' => trim($a['title']), 'kind' => $a['kind'], 'owner_employee_id' => $a['owner_employee_id'] ?? null, 'due_on' => $a['due_on']];
                    $act = ! empty($a['id']) ? DevelopmentActivity::where('development_plan_id', $p->id)->find($a['id']) : null;
                    if ($act) { if ($act->due_on->toDateString() !== $a['due_on']) $vals['alerted_at'] = null; $act->update($vals); }
                    else $act = DevelopmentActivity::create($vals + ['development_plan_id' => $p->id]);
                    $keep[] = $act->id;
                }
                DevelopmentActivity::where('development_plan_id', $p->id)->whereNotIn('id', $keep)->whereNotIn('status', ['done'])->delete();
            }
            return $p->refresh();
        });
    }

    public function activate(DevelopmentPlan $p, User $by): DevelopmentPlan
    {
        if ($p->status !== 'draft') throw new ApiException(L::t('الخطة مفعّلة أو مكتملة.', 'The plan is already active or done.'), 'plan_state', 409);
        if (! $p->activities()->exists()) throw new ApiException(L::t('أضف نشاطاً واحداً على الأقل قبل التفعيل.', 'Add at least one activity before activating.'), 'no_activities', 422);
        $p->update(['status' => 'active', 'activated_at' => now(), 'next_review' => now()->addMonthsNoOverflow((int) Settings::development()['review_months'])->toDateString()]);
        $p->loadMissing(['employee', 'department']);
        $p->employee?->log("فُعّلت خطة التطوير «{$p->title}»", "Development plan «{$p->title}» activated", 'info', 'IDP');
        [$ar, $en] = self::subject($p);
        foreach ($this->people($p) as $uid) Notify::user($uid, "خطة تطوير «{$p->title}» {$ar} أصبحت جارية", "Development plan «{$p->title}» {$en} is active", 'development');
        return $p;
    }

    /** تقدّم نشاط: الموظف صاحب الخطة، أو مسؤول النشاط، أو من يملك تعديل الخطط في نطاقه */
    public function progress(DevelopmentActivity $a, User $u, array $in): DevelopmentActivity
    {
        $p = $a->plan()->with('employee')->firstOrFail();
        if ($p->status !== 'active') throw new ApiException(L::t('الخطة ليست جارية.', 'The plan is not active.'), 'plan_state', 409);
        if (! in_array($u->employee_id, [$p->employee_id, $a->owner_employee_id], true) && ! $this->canEdit($u, $p)) throw new ApiException(L::t('لا تملك تحديث هذا النشاط.', 'You cannot update this activity.'), 'forbidden', 403);
        $d = Validator::make($in, ['status' => ['required', 'in:planned,in_progress,done,cancelled'], 'progress' => ['nullable', 'integer', 'between:0,100'], 'note' => ['nullable', 'string', 'max:1000']])->validate();
        if ($d['status'] === 'cancelled' && ! $this->canEdit($u, $p)) throw new ApiException(L::t('إلغاء النشاط لمدير الخطة.', 'Only the plan manager can cancel an activity.'), 'forbidden', 403);
        $progress = $d['status'] === 'done' ? 100 : (int) ($d['progress'] ?? $a->progress);
        $a->update(['status' => $d['status'], 'progress' => $progress, 'note' => trim((string) ($d['note'] ?? '')) ?: $a->note, 'done_at' => $d['status'] === 'done' ? now() : null]);
        if ($d['status'] === 'done') $p->employee?->log("أُنجز نشاط التطوير «{$a->title}»", "Development activity «{$a->title}» done", 'ok', 'IDP');
        return $a;
    }

    /** إعادة التقييم: ملاحظة وتقدير 1–5، وتحديد الهدف المنجز، وموعد المراجعة التالي — أو إكمال الخطة */
    public function review(DevelopmentPlan $p, User $by, array $in): DevelopmentPlan
    {
        if ($p->status !== 'active') throw new ApiException(L::t('الخطة ليست جارية.', 'The plan is not active.'), 'plan_state', 409);
        $d = Validator::make($in, ['note' => ['required', 'string', 'min:5', 'max:2000'], 'rating' => ['required', 'integer', 'between:1,5'], 'complete' => ['nullable', 'boolean'], 'goals_done' => ['nullable', 'array'], 'goals_done.*' => ['integer']],
            [], ['note' => L::t('ملاحظة المراجعة', 'Review note')])->validate();
        $goals = $p->goals;
        foreach ($d['goals_done'] ?? [] as $i) if (isset($goals[$i])) $goals[$i]['done'] = true;
        $reviews = $p->reviews ?? [];
        $reviews[] = ['at' => now()->toIso8601String(), 'by' => $by->displayName('ar'), 'by_en' => $by->displayName('en'), 'note' => trim($d['note']), 'rating' => (int) $d['rating']];
        $done = (bool) ($d['complete'] ?? false);
        $p->update(['goals' => $goals, 'reviews' => $reviews, 'review_alerted_at' => null] + ($done ? ['status' => 'done', 'completed_at' => now(), 'next_review' => null]
            : ['next_review' => now()->addMonthsNoOverflow((int) Settings::development()['review_months'])->toDateString()]));
        if ($done) DevelopmentActivity::where('development_plan_id', $p->id)->whereIn('status', ['planned', 'in_progress'])->update(['status' => 'cancelled']);
        $p->loadMissing('employee');
        $p->employee?->log($done ? "اكتملت خطة التطوير «{$p->title}»" : "مراجعة خطة التطوير «{$p->title}» ({$d['rating']}/5)", $done ? "Development plan «{$p->title}» completed" : "Development plan «{$p->title}» reviewed ({$d['rating']}/5)", $done ? 'ok' : 'info', 'IDP');
        if ($p->employee_id && ($uid = User::where('employee_id', $p->employee_id)->value('id'))) Notify::user($uid, $done ? "اكتملت خطة تطويرك «{$p->title}»" : "رُوجعت خطة تطويرك «{$p->title}»", $done ? "Your development plan «{$p->title}» is complete" : "Your development plan «{$p->title}» was reviewed", 'development');
        return $p;
    }

    /** يومياً: نشاط متأخر ← تنبيه مرة واحدة؛ ومراجعة مستحقة ← تنبيه المدير والموارد البشرية */
    public function sweep(): array
    {
        $out = ['overdue' => 0, 'review' => 0];
        $today = now()->toDateString();
        $acts = DevelopmentActivity::with(['plan.employee', 'plan.department', 'owner'])->whereIn('status', ['planned', 'in_progress'])->whereNull('alerted_at')->where('due_on', '<', $today)
            ->whereHas('plan', fn ($q) => $q->where('status', 'active'))->get();
        foreach ($acts as $a) {
            $p = $a->plan;
            [$ar, $en] = self::subject($p);
            foreach ($this->people($p, $a) as $uid) Notify::user($uid, "نشاط تطوير متأخر: «{$a->title}» ({$ar}) — كان موعده {$a->due_on->toDateString()}", "Overdue development activity «{$a->title}» ({$en}) — due {$a->due_on->toDateString()}", 'development');
            $a->update(['alerted_at' => now()]);
            $out['overdue']++;
        }
        foreach (DevelopmentPlan::with(['employee', 'department'])->where('status', 'active')->whereNotNull('next_review')->where('next_review', '<=', $today)->whereNull('review_alerted_at')->get() as $p) {
            [$ar, $en] = self::subject($p);
            $mgr = $p->employee?->manager_id ?? $p->department?->head_id;
            if ($mgr && ($m = User::where('employee_id', $mgr)->value('id'))) Notify::user($m, "حان موعد مراجعة خطة التطوير «{$p->title}» {$ar}", "Development plan review due: «{$p->title}» {$en}", 'development');
            Notify::hr("حان موعد مراجعة خطة التطوير «{$p->title}» {$ar}", "Development plan review due: «{$p->title}» {$en}", 'development');
            $p->update(['review_alerted_at' => now()]);
            $out['review']++;
        }
        return $out;
    }

    public function canEdit(User $u, DevelopmentPlan $p): bool
    {
        if ($p->scope === 'company') return Succession::manages($u);
        if ($p->scope === 'department') return Succession::manages($u) || ($u->employee_id && \App\Models\Department::whereKey($p->department_id)->where('head_id', $u->employee_id)->exists());
        return Perm::can($u, 'succession', 'edit') && $p->employee_id !== $u->employee_id && Directory::scoped($u, 'succession')->whereKey($p->employee_id)->exists();
    }

    /** المعنيّون بالخطة: الموظف، مديره، ومسؤولو الأنشطة (أو مسؤول نشاط واحد) */
    private function people(DevelopmentPlan $p, ?DevelopmentActivity $a = null): array
    {
        $p->loadMissing(['employee', 'department']);
        $owners = $a ? [$a->owner_employee_id] : $p->activities()->pluck('owner_employee_id')->all();
        $emps = match ($p->scope) {
            // خطة إدارة: رئيسها وموظفوها ومسؤولو الأنشطة؛ خطة الشركة: مسؤولو الأنشطة (والموارد البشرية بإشعار عام)
            'department' => [$p->department?->head_id, ...$owners, ...($a ? [] : Employee::current()->where('department_id', $p->department_id)->pluck('id')->all())],
            'company' => $owners,
            default => [$p->employee_id, $p->employee?->manager_id, ...$owners],
        };
        $emps = array_filter($emps);
        return User::where('active', true)->whereIn('employee_id', array_unique($emps))->pluck('id')->all();
    }

    /** وصف صاحب الخطة للإشعارات: «لـ اسم الموظف» / «لإدارة …» / «للشركة» */
    public static function subject(DevelopmentPlan $p): array
    {
        return match ($p->scope) {
            'department' => ['لإدارة '.($p->department?->name_ar ?? ''), 'for '.($p->department?->name_en ?? 'the department')],
            'company' => ['للشركة', 'for the company'],
            default => ['لـ '.($p->employee?->name ?? ''), 'for '.($p->employee?->name_en ?? '')],
        };
    }

    public static function row(DevelopmentPlan $p, ?User $viewer = null): array
    {
        $e = $p->employee;
        $acts = $p->relationLoaded('activities') ? $p->activities : collect();
        $today = now()->toDateString();
        $edit = $viewer ? app(self::class)->canEdit($viewer, $p) : false;
        return ['id' => $p->id, 'title' => $p->title, 'gaps' => $p->gaps, 'goals' => $p->goals, 'status' => $p->status, 'source' => $p->source, 'scope' => $p->scope,
            'employee' => $e ? ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en]] : null,
            'department' => $p->scope === 'department' && $p->relationLoaded('department') && $p->department ? ['id' => $p->department->id, 'ar' => $p->department->name_ar, 'en' => $p->department->name_en] : null,
            'next_review' => $p->next_review?->toDateString(), 'review_due' => $p->status === 'active' && $p->next_review && $p->next_review->toDateString() <= $today,
            'reviews' => $p->reviews ?? [], 'activated_at' => $p->activated_at?->toIso8601String(), 'completed_at' => $p->completed_at?->toIso8601String(),
            'progress' => $acts->where('status', '!=', 'cancelled')->count() ? (int) round($acts->where('status', '!=', 'cancelled')->avg('progress')) : 0,
            'activities' => $acts->map(fn (DevelopmentActivity $a) => ['id' => $a->id, 'title' => $a->title, 'kind' => $a->kind, 'due_on' => $a->due_on->toDateString(), 'status' => $a->status, 'progress' => $a->progress, 'note' => $a->note,
                'overdue' => in_array($a->status, ['planned', 'in_progress'], true) && $a->due_on->toDateString() < $today,
                'owner' => $a->owner ? ['id' => $a->owner->id, 'name' => ['ar' => $a->owner->name, 'en' => $a->owner->name_en]] : null,
                'can_update' => $viewer && $p->status === 'active' && (in_array($viewer->employee_id, array_filter([$p->employee_id, $a->owner_employee_id]), true) || $edit)])->values(),
            'can' => ['edit' => $edit]];
    }
}
