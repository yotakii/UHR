<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competency;
use App\Models\Department;
use App\Models\DevelopmentActivity;
use App\Models\DevelopmentPlan;
use App\Models\Employee;
use App\Models\SuccessionCandidate;
use App\Models\SuccessionPlan;
use App\Services\Development\DevelopmentPlans;
use App\Services\Development\Succession;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * التعاقب وخطط التطوير (D-23، D-24) — من البوابتين: الموظف يرى خطته وأنشطته، المدير خطط فريقه وترشيحاته،
 * والموارد البشرية كل المناصب الحرجة والخطط. الصلاحية «succession» بنطاقها (own/team/all).
 */
class DevelopmentController extends Controller
{
    private const PLAN_WITH = ['employee', 'activities.owner'];

    public function __construct(private DevelopmentPlans $plans, private Succession $succession) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $scope = Directory::scoped($u, 'succession');
        $plans = DevelopmentPlan::with(self::PLAN_WITH)->whereIn('employee_id', (clone $scope)->select('id'))->orderByRaw("case status when 'active' then 0 when 'draft' then 1 else 2 end")->latest('id')->limit(300)->get();
        // أنشطة أنا مسؤول عنها في خطط غيري
        $owned = $u->employee_id ? DevelopmentActivity::with(['plan.employee'])->where('owner_employee_id', $u->employee_id)->whereHas('plan', fn ($q) => $q->where('status', 'active')->where('employee_id', '!=', $u->employee_id))->get() : collect();
        $manage = Succession::manages($u);
        $team = $manage || Perm::scope($u, 'succession') === 'team';
        $sp = $team ? SuccessionPlan::with(['department', 'incumbent', 'candidates.employee', 'candidates.developmentPlan', 'candidates.nominator.employee'])
            ->when(! $manage, fn ($q) => $q->whereIn('status', ['active', 'reviewed']))->orderByRaw("case status when 'active' then 0 when 'reviewed' then 1 else 2 end")->orderBy('title_ar')->get() : collect();
        return response()->json(['plans' => $plans->map(fn ($p) => DevelopmentPlans::row($p, $u)),
            'owned' => $owned->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'kind' => $a->kind, 'due_on' => $a->due_on->toDateString(), 'status' => $a->status, 'progress' => $a->progress,
                'overdue' => in_array($a->status, ['planned', 'in_progress'], true) && $a->due_on->isPast(), 'employee' => ['ar' => $a->plan->employee->name, 'en' => $a->plan->employee->name_en], 'plan' => $a->plan->title])->values(),
            'succession' => $sp->map(fn ($p) => Succession::row($p, $u)),
            'employees' => (Perm::can($u, 'succession', 'create') ? (clone $scope)->current()->where('id', '!=', $u->employee_id ?? 0)->orderBy('code')->get() : collect())
                ->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'dept' => $e->department_id])->values(),
            'owners' => Employee::current()->orderBy('code')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
            'competencies' => Competency::where('active', true)->orderBy('sort')->get()->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en]),
            'departments' => $manage ? Department::orderBy('id')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]) : [],
            'settings' => Settings::development(), 'kinds' => DevelopmentPlans::KINDS, 'me' => $u->employee_id,
            'can' => ['create' => Perm::can($u, 'succession', 'create'), 'edit' => Perm::can($u, 'succession', 'edit'), 'manage' => $manage, 'team' => $team, 'settings' => Perm::can($u, 'settings', 'edit')]]);
    }

    // ── خطط التطوير ──

    public function storePlan(Request $request): JsonResponse
    {
        $p = $this->plans->save(null, $request->all(), $request->user());
        return response()->json(['data' => DevelopmentPlans::row($p->load(self::PLAN_WITH), $request->user())], 201);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $p = $this->editable($request, $id);
        return response()->json(['data' => DevelopmentPlans::row($this->plans->save($p, $request->all(), $request->user())->load(self::PLAN_WITH), $request->user())]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => DevelopmentPlans::row($this->plans->activate($this->editable($request, $id), $request->user())->load(self::PLAN_WITH), $request->user())]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => DevelopmentPlans::row($this->plans->review($this->editable($request, $id), $request->user(), $request->all())->load(self::PLAN_WITH), $request->user())]);
    }

    public function progress(Request $request, int $id): JsonResponse
    {
        $this->plans->progress(DevelopmentActivity::findOrFail($id), $request->user(), $request->all());
        return response()->json(['ok' => true]);
    }

    // ── التعاقب ──

    public function storeSuccession(Request $request): JsonResponse
    {
        $this->manageOnly($request);
        return response()->json(['data' => Succession::row($this->succession->save(null, $request->all())->load($this->spWith()), $request->user())], 201);
    }

    public function updateSuccession(Request $request, int $id): JsonResponse
    {
        $this->manageOnly($request);
        return response()->json(['data' => Succession::row($this->succession->save(SuccessionPlan::findOrFail($id), $request->all())->load($this->spWith()), $request->user())]);
    }

    public function activateSuccession(Request $request, int $id): JsonResponse
    {
        $this->manageOnly($request);
        return response()->json(['data' => Succession::row($this->succession->activate(SuccessionPlan::findOrFail($id))->load($this->spWith()), $request->user())]);
    }

    public function reviewSuccession(Request $request, int $id): JsonResponse
    {
        $this->manageOnly($request);
        $p = $this->succession->review(SuccessionPlan::findOrFail($id), $request->user(), (string) $request->input('note'));
        return response()->json(['data' => Succession::row($p->load($this->spWith()), $request->user())]);
    }

    public function nominate(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'readiness' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->succession->nominate(SuccessionPlan::findOrFail($id), Employee::current()->findOrFail($d['employee_id']), $d['readiness'], $d['note'] ?? null, $request->user());
        return response()->json(['data' => Succession::row(SuccessionPlan::with($this->spWith())->findOrFail($id), $request->user())]);
    }

    public function assess(Request $request, int $id): JsonResponse
    {
        $this->manageOnly($request);
        $c = $this->succession->assess(SuccessionCandidate::findOrFail($id), $request->all(), $request->user());
        return response()->json(['data' => Succession::row(SuccessionPlan::with($this->spWith())->findOrFail($c->succession_plan_id), $request->user())]);
    }

    public function removeCandidate(Request $request, int $id): JsonResponse
    {
        $c = SuccessionCandidate::findOrFail($id);
        $this->succession->remove($c, $request->user());
        return response()->json(['data' => Succession::row(SuccessionPlan::with($this->spWith())->findOrFail($c->succession_plan_id), $request->user())]);
    }

    // ── الإعدادات ──

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['review_months' => ['required', 'integer', 'between:1,24'], 'succession_review_months' => ['required', 'integer', 'between:1,36'],
            'readiness' => ['required', 'array', 'size:3'], 'readiness.*.key' => ['required', 'in:now,1y,3y'], 'readiness.*.ar' => ['required', 'string', 'min:2', 'max:60'], 'readiness.*.en' => ['required', 'string', 'min:2', 'max:60']]);
        if (count(array_unique(array_column($d['readiness'], 'key'))) !== 3) throw ValidationException::withMessages(['readiness' => [L::t('المقياس ثلاث درجات ثابتة.', 'The scale has three fixed levels.')]]);
        $before = Settings::development();
        Settings::put('development', $d);
        Audit::record('settings.development', null, $before, $d);
        return response()->json(['settings' => Settings::development()]);
    }

    private function spWith(): array { return ['department', 'incumbent', 'candidates.employee', 'candidates.developmentPlan', 'candidates.nominator.employee']; }

    private function manageOnly(Request $request): void
    {
        if (! Succession::manages($request->user())) abort(response()->json(['message' => L::t('إدارة المناصب الحرجة للموارد البشرية.', 'Critical positions are managed by HR.'), 'code' => 'forbidden'], 403));
    }

    private function editable(Request $request, int $id): DevelopmentPlan
    {
        $p = DevelopmentPlan::with('employee')->findOrFail($id);
        if (! $this->plans->canEdit($request->user(), $p)) abort(response()->json(['message' => L::t('لا تملك تعديل هذه الخطة.', 'You cannot edit this plan.'), 'code' => 'forbidden'], 403));
        return $p;
    }
}
