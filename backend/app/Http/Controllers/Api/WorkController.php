<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\WorkTask;
use App\Services\People\Directory;
use App\Services\Work\Kpis;
use App\Services\Work\Points;
use App\Services\Work\Tasks;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المهام ومؤشرات الأداء (ملاحظات 61/62) من البوابتين: كل موظف يرى مهامه ومؤشراته ويضيف مهامه الخاصة،
 * المدير يكلّف فريقه ويعرّف مؤشراتهم، والموارد البشرية الكل. صلاحية «tasks» بنطاقها (own/team/all).
 */
class WorkController extends Controller
{
    private const TASK_WITH = ['employee', 'creator', 'kpi'];

    public function __construct(private Tasks $tasks, private Kpis $kpis) {}

    public function tasks(Request $request): JsonResponse
    {
        $u = $request->user();
        $f = $request->validate(['view' => ['nullable', 'in:mine,team,created'], 'status' => ['nullable', 'in:open,review,done,cancelled,all'], 'q' => ['nullable', 'string', 'max:80'], 'employee' => ['nullable', 'integer']]);
        $view = $f['view'] ?? 'mine';
        $team = Perm::can($u, 'tasks') && Perm::scope($u, 'tasks') !== 'own';
        $q = WorkTask::with(self::TASK_WITH)->withCount('comments');
        match ($view) {
            'team' => $team ? $q->whereIn('employee_id', Directory::scoped($u, 'tasks')->select('id'))->where('employee_id', '!=', $u->employee_id ?? 0) : $q->whereRaw('1 = 0'),
            'created' => $q->where('created_by', $u->id)->where('employee_id', '!=', $u->employee_id ?? 0),
            default => $q->where('employee_id', $u->employee_id ?? 0),
        };
        $status = $f['status'] ?? 'open';
        match ($status) {
            'open' => $q->whereIn('status', ['todo', 'in_progress', 'review']), 'all' => null, default => $q->where('status', $status),
        };
        if (! empty($f['employee'])) $q->where('employee_id', $f['employee']);
        if ($s = trim((string) ($f['q'] ?? ''))) $q->where(fn ($w) => $w->where('title', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%"));
        $rows = $q->orderByRaw("case status when 'review' then 0 when 'in_progress' then 1 when 'todo' then 2 else 3 end")->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')->limit(300)->get();
        $base = WorkTask::query();
        $counts = [
            'mine' => (clone $base)->where('employee_id', $u->employee_id ?? 0)->whereIn('status', ['todo', 'in_progress', 'review'])->count(),
            'team_review' => $team ? (clone $base)->whereIn('employee_id', Directory::scoped($u, 'tasks')->select('id'))->where('status', 'review')->count() : 0,
            'overdue' => (clone $base)->where('employee_id', $u->employee_id ?? 0)->whereIn('status', ['todo', 'in_progress'])->where('due_on', '<', now()->toDateString())->count(),
        ];
        return response()->json(['data' => $rows->map(fn ($t) => Tasks::row($t, $u))->values(), 'counts' => $counts,
            'people' => $this->people($u), 'kpis' => Kpis::visible($u)->where('active', true)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])->map(fn ($k) => ['id' => $k->id, 'ar' => $k->name_ar, 'en' => $k->name_en ?: $k->name_ar]),
            'points' => Points::config(), 'me' => $u->employee_id,
            'can' => ['team' => $team, 'assign' => Perm::can($u, 'tasks', 'create'), 'self' => (bool) $u->employee_id, 'settings' => Perm::can($u, 'settings', 'edit')]]);
    }

    public function task(Request $request, int $id): JsonResponse
    {
        $t = $this->visibleTask($request, $id);
        return response()->json(['data' => Tasks::row($t->load([...self::TASK_WITH, 'comments.user']), $request->user(), true)]);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $t = $this->tasks->save(null, $request->all(), $request->user());
        return response()->json(['data' => Tasks::row($t->load(self::TASK_WITH), $request->user())], 201);
    }

    public function updateTask(Request $request, int $id): JsonResponse
    {
        $t = $this->visibleTask($request, $id);
        if (! Tasks::canManage($request->user(), $t)) throw new ApiException(L::t('لا تملك تعديل هذه المهمة.', 'You cannot edit this task.'), 'forbidden', 403);
        return response()->json(['data' => Tasks::row($this->tasks->save($t, $request->all(), $request->user())->load(self::TASK_WITH), $request->user())]);
    }

    public function progress(Request $request, int $id): JsonResponse
    {
        $t = $this->visibleTask($request, $id);
        return response()->json(['data' => Tasks::row($this->tasks->progress($t, $request->user(), $request->all())->load(self::TASK_WITH), $request->user())]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $t = $this->visibleTask($request, $id);
        return response()->json(['data' => Tasks::row($this->tasks->decide($t, $request->user(), (bool) $d['approve'], $d['note'] ?? null)->load(self::TASK_WITH), $request->user())]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $t = $this->visibleTask($request, $id);
        return response()->json(['data' => Tasks::row($this->tasks->cancel($t, $request->user(), $note)->load(self::TASK_WITH), $request->user())]);
    }

    public function comment(Request $request, int $id): JsonResponse
    {
        $body = (string) $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']])['body'];
        $t = $this->visibleTask($request, $id);
        $this->tasks->comment($t, $request->user(), $body);
        return $this->task($request, $id);
    }

    // ── مؤشرات الأداء ──

    public function kpis(Request $request): JsonResponse
    {
        $u = $request->user();
        $scope = $request->validate(['scope' => ['nullable', 'in:all,employee,department,company'], 'active' => ['nullable', 'boolean']])['scope'] ?? 'all';
        $rows = Kpis::visible($u)->with(['employee', 'department', 'entries'])->when($scope !== 'all', fn ($q) => $q->where('scope', $scope))
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('active', true))
            ->orderByRaw("case scope when 'company' then 0 when 'department' then 1 else 2 end")->orderBy('name_ar')->limit(500)->get();
        $hr = Kpis::isHr($u);
        $heads = $u->employee_id ? Department::where('head_id', $u->employee_id)->pluck('id')->all() : [];
        return response()->json(['data' => $rows->map(fn ($k) => Kpis::row($k, $u))->values(),
            'people' => $this->people($u),
            'departments' => Department::where('active', true)->when(! $hr, fn ($q) => $q->whereIn('id', $heads ?: [0]))->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'units' => Kpi::UNITS, 'periods' => Kpi::PERIODS,
            'can' => ['employee' => Perm::can($u, 'tasks', 'edit'), 'department' => $hr || ! empty($heads), 'company' => $hr]]);
    }

    public function storeKpi(Request $request): JsonResponse
    {
        $k = $this->kpis->save(null, $request->all(), $request->user());
        return response()->json(['data' => Kpis::row($k->load(['employee', 'department', 'entries']), $request->user())], 201);
    }

    public function updateKpi(Request $request, int $id): JsonResponse
    {
        $k = Kpis::visible($request->user())->findOrFail($id);
        return response()->json(['data' => Kpis::row($this->kpis->save($k, $request->all(), $request->user())->load(['employee', 'department', 'entries']), $request->user())]);
    }

    public function record(Request $request, int $id): JsonResponse
    {
        $k = Kpis::visible($request->user())->findOrFail($id);
        $this->kpis->record($k, $request->user(), $request->all());
        return response()->json(['data' => Kpis::row($k->fresh(['employee', 'department', 'entries']), $request->user())]);
    }

    // ── إعداد النقاط (ملاحظة 61) ──

    public function pointsSettings(): JsonResponse
    {
        return response()->json(['data' => Points::config(), 'defaults' => Settings::DEFAULTS['points']]);
    }

    public function savePoints(Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'task' => ['required', 'array'], 'task.low' => ['required', 'integer', 'between:0,1000'], 'task.normal' => ['required', 'integer', 'between:0,1000'],
            'task.high' => ['required', 'integer', 'between:0,1000'], 'task.urgent' => ['required', 'integer', 'between:0,1000'], 'late_factor' => ['required', 'numeric', 'between:0,1'],
            'kpi_met' => ['required', 'integer', 'between:0,1000'], 'kpi_exceed' => ['required', 'integer', 'between:0,1000'], 'exceed_pct' => ['required', 'integer', 'between:100,200'],
            'review' => ['required', 'array'], 'review.high' => ['required', 'integer', 'between:0,5000'], 'review.mid' => ['required', 'integer', 'between:0,5000'], 'review.low' => ['required', 'integer', 'between:0,5000']]);
        $before = Points::config();
        $new = ['enabled' => (bool) $d['enabled'], 'task' => array_map('intval', array_intersect_key($d['task'], Settings::DEFAULTS['points']['task'])), 'late_factor' => (float) $d['late_factor'],
            'kpi_met' => (int) $d['kpi_met'], 'kpi_exceed' => (int) $d['kpi_exceed'], 'exceed_pct' => (int) $d['exceed_pct'], 'review' => array_map('intval', array_intersect_key($d['review'], Settings::DEFAULTS['points']['review']))];
        Settings::put('points', $new);
        Audit::record('settings.points', null, $before, $new);
        return response()->json(['data' => Points::config()]);
    }

    /** من يمكن تكليفه أو تعريف مؤشر له: نطاق المستخدم في «المهام» (بلا نفسه) */
    private function people($u): array
    {
        if (! Perm::can($u, 'tasks', 'create') && ! Perm::can($u, 'tasks', 'edit')) return [];
        return Directory::scoped($u, 'tasks')->current()->where('id', '!=', $u->employee_id ?? 0)->orderBy('code')->limit(1000)->get(['id', 'code', 'name', 'name_en', 'department_id'])
            ->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'dept' => $e->department_id])->all();
    }

    private function visibleTask(Request $request, int $id): WorkTask
    {
        $t = WorkTask::with(self::TASK_WITH)->findOrFail($id);
        abort_unless(Tasks::canSee($request->user(), $t), 404);
        return $t;
    }
}
