<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\ViolationType;
use App\Services\Discipline\Complaints;
use App\Services\Discipline\Discipline;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\ViolationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** M17 — سجل المخالفات، الحالات المفتوحة بمهلتها، الاقتراح الآلي من الحضور، دليل المخالفات، والشكاوى (لنطاق «الكل» فقط) */
class DisciplineController extends Controller
{
    private const WITH = ['employee.department', 'type', 'opener.employee', 'decider.employee'];

    public function __construct(private Discipline $discipline, private Complaints $complaints) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['tab' => ['nullable', 'in:open,all,decided'], 'q' => ['nullable', 'string', 'max:100'], 'employee' => ['nullable', 'integer']]);
        $u = $request->user();
        $q = DisciplineCase::with(self::WITH)->whereIn('employee_id', Directory::scoped($u, 'discipline')->select('id'))
            ->when(($f['tab'] ?? 'open') === 'open', fn ($w) => $w->whereIn('status', DisciplineCase::OPEN))
            ->when(($f['tab'] ?? null) === 'decided', fn ($w) => $w->where('status', 'decided'))
            ->when($f['employee'] ?? null, fn ($w, $id) => $w->where('employee_id', $id))
            ->when($f['q'] ?? null, fn ($w, $s) => $w->where(fn ($x) => $x->where('ref_no', 'ilike', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")->orWhere('name_en', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%"))));
        $rows = $q->orderByRaw("case when status in ('open','investigated','pending_gm') then 0 else 1 end")->orderBy('deadline')->latest('id')->limit(500)->get();
        $all = Perm::scope($u, 'discipline') === 'all';
        $base = DisciplineCase::whereIn('employee_id', Directory::scoped($u, 'discipline')->select('id'));
        return response()->json(['data' => $rows->map(fn ($c) => Discipline::row($c, $u)),
            'counts' => ['open' => (clone $base)->whereIn('status', DisciplineCase::OPEN)->count(), 'due_soon' => (clone $base)->whereIn('status', ['open', 'investigated'])->whereDate('deadline', '<=', now()->addDays(3)->toDateString())->count(),
                'month' => (clone $base)->where('status', 'decided')->where('decided_at', '>=', now()->startOfMonth())->count(), 'complaints' => $all ? Complaint::whereIn('status', ['received', 'reviewing'])->count() : null],
            'employees' => Directory::scoped($u, 'discipline')->current()->orderBy('code')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
            'types' => ViolationType::where('active', true)->orderByRaw("case \"group\" when 'time' then 0 when 'work' then 1 else 2 end")->orderBy('number')->get()->map(fn ($t) => Discipline::typeRow($t)),
            'groups' => collect(ViolationCatalog::GROUPS)->map(fn ($g, $k) => ['key' => $k, 'ar' => $g[0], 'en' => $g[1]])->values(),
            'kinds' => collect(ViolationCatalog::KINDS)->map(fn ($g, $k) => ['key' => $k, 'ar' => $g[0], 'en' => $g[1]])->values(),
            'policy' => Settings::policy('discipline'), 'reject_min' => (int) Settings::policy('leave_rules')['reject_min'],
            'can' => ['create' => Perm::can($u, 'discipline', 'create'), 'decide' => Perm::can($u, 'discipline', 'decide'), 'edit' => Perm::can($u, 'discipline', 'edit'), 'complaints' => $all,
                'print' => Perm::can($u, 'discipline', 'print'), 'catalog' => Perm::can($u, 'settings', 'edit')]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => Discipline::row($this->find($request, $id), $request->user(), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'violation_type_id' => ['required', 'integer'], 'occurred_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'min:5', 'max:2000'], 'source' => ['nullable', 'in:manual,attendance']]);
        $e = Directory::scoped($request->user(), 'discipline')->current()->findOrFail($d['employee_id']);
        $c = $this->discipline->open($request->user()->loadMissing('roles'), $e, $d, $d['source'] ?? 'manual');
        return response()->json(['data' => Discipline::row($c->load(self::WITH), $request->user(), true)], 201);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $m = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        return response()->json(['data' => $this->discipline->suggestions($request->user(), $m), 'month' => $m ?? now()->format('Y-m')]);
    }

    public function investigate(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['statement' => ['required', 'string', 'min:5', 'max:4000'], 'witnesses' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:2000'], 'union_notified' => ['nullable', 'boolean']]);
        $c = $this->discipline->investigate($this->find($request, $id), $request->user(), $d);
        return response()->json(['data' => Discipline::row($c->load(self::WITH), $request->user(), true)]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', 'string'], 'days' => ['nullable', 'numeric'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $c = $this->discipline->decide($this->find($request, $id), $request->user(), ['kind' => $d['kind'], 'days' => $d['days'] ?? null], $d['reason'] ?? null);
        return response()->json(['data' => Discipline::row($c->load(self::WITH), $request->user(), true)]);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        $d = $request->validate(['reason' => ['required', 'string', "min:{$min}", 'max:1000']]);
        $c = $this->discipline->dismiss($this->find($request, $id), $request->user(), $d['reason']);
        return response()->json(['data' => Discipline::row($c->load(self::WITH), $request->user(), true)]);
    }

    /** نص الإنذار F-01 للطباعة */
    public function warning(Request $request, int $id): JsonResponse
    {
        $c = $this->find($request, $id);
        if ($c->status !== 'decided') throw new ApiException(L::t('لا قرار في هذه الحالة.', 'No decision in this case.'), 'not_decided', 409);
        return response()->json(['data' => Discipline::row($c, $request->user(), true) + ['company' => ['ar' => Settings::company('name_ar'), 'en' => Settings::company('name_en')]]]);
    }

    // ── دليل المخالفات (M16) ──

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => ViolationType::orderByRaw("case \"group\" when 'time' then 0 when 'work' then 1 else 2 end")->orderBy('number')->get()->map(fn ($t) => Discipline::typeRow($t)
            + ['used' => DisciplineCase::where('violation_type_id', $t->id)->count()]),
            'groups' => collect(ViolationCatalog::GROUPS)->map(fn ($g, $k) => ['key' => $k, 'ar' => $g[0], 'en' => $g[1]])->values(),
            'kinds' => collect(ViolationCatalog::KINDS)->map(fn ($g, $k) => ['key' => $k, 'ar' => $g[0], 'en' => $g[1]])->values(),
            'autos' => ['late15', 'late30', 'late60', 'late_over', 'absent', 'early']]);
    }

    public function saveType(Request $request, ?int $id = null): JsonResponse
    {
        $d = $request->validate(['group' => ['required', 'in:'.implode(',', array_keys(ViolationCatalog::GROUPS))], 'title_ar' => ['required', 'string', 'min:3', 'max:500'], 'title_en' => ['required', 'string', 'min:3', 'max:500'],
            'article' => ['nullable', 'string', 'max:40'], 'auto' => ['nullable', 'in:late15,late30,late60,late_over,absent,early'], 'active' => ['nullable', 'boolean'],
            'penalties' => ['required', 'array', 'min:1', 'max:6'], 'penalties.*.kind' => ['required', 'in:'.implode(',', array_keys(ViolationCatalog::KINDS))], 'penalties.*.days' => ['nullable', 'numeric', 'between:0.25,30']],
            [], ['title_ar' => L::t('نص المخالفة بالعربية', 'Arabic text'), 'title_en' => L::t('نص المخالفة بالإنكليزية', 'English text'), 'penalties' => L::t('الجزاءات', 'Penalties')]);
        $pens = array_map(function ($p) {
            if ($p['kind'] !== 'deduct') return ['kind' => $p['kind']];
            if (empty($p['days'])) throw ApiException::field('penalties', L::t('حدّد أيام الحسم.', 'Set the deduction days.'), 'days_required');
            return ['kind' => 'deduct', 'days' => round((float) $p['days'], 2)];
        }, array_values($d['penalties']));
        // الجزاءات تتصاعد مع التكرار
        for ($i = 1; $i < count($pens); $i++) if (Discipline::rank($pens[$i]) < Discipline::rank($pens[$i - 1])) throw ApiException::field('penalties', L::t('جزاء المرة التالية لا يكون أخف من السابقة.', 'A repeat penalty cannot be lighter than the previous one.'), 'not_ascending');
        $row = ['group' => $d['group'], 'title_ar' => trim($d['title_ar']), 'title_en' => trim($d['title_en']), 'article' => trim((string) ($d['article'] ?? '')) ?: null, 'penalties' => $pens,
            'auto' => $d['auto'] ?? null, 'active' => $d['active'] ?? true];
        $t = DB::transaction(function () use ($id, $row) {
            if ($row['auto']) ViolationType::where('auto', $row['auto'])->when($id, fn ($q) => $q->where('id', '!=', $id))->update(['auto' => null]);
            if ($id) { $t = ViolationType::findOrFail($id); $t->update($row); return $t; }
            return ViolationType::create($row + ['number' => (int) ViolationType::where('group', $row['group'])->max('number') + 1]);
        });
        return response()->json(['data' => Discipline::typeRow($t)], $id ? 200 : 201);
    }

    public function updateType(Request $request, int $id): JsonResponse { return $this->saveType($request, $id); }

    /** إعادة الدليل للافتراضي: البنود غير المستخدمة تُحذف، والمستخدمة تُعطَّل، ثم يُبذر الدليل الافتراضي */
    public function resetCatalog(): JsonResponse
    {
        DB::transaction(function () {
            $used = DisciplineCase::distinct()->pluck('violation_type_id');
            ViolationType::whereNotIn('id', $used)->delete();
            ViolationType::whereIn('id', $used)->update(['active' => false, 'auto' => null]);
            $n = ['time' => 0, 'work' => 0, 'conduct' => 0];
            $max = ViolationType::selectRaw('"group", max(number) as n')->groupBy('group')->pluck('n', 'group');
            foreach (ViolationCatalog::DEFAULTS as [$group, $ar, $en, $article, $pen, $auto]) {
                ViolationType::create(['group' => $group, 'number' => (int) ($max[$group] ?? 0) + ++$n[$group], 'title_ar' => $ar, 'title_en' => $en, 'article' => $article ?: null,
                    'penalties' => array_map([ViolationCatalog::class, 'penalty'], $pen), 'auto' => $auto, 'active' => true]);
            }
            Audit::record('discipline.catalog_reset', null);
        });
        return $this->catalog();
    }

    // ── الشكاوى (سرية: نطاق «الكل» فقط) ──

    public function complaints(Request $request): JsonResponse
    {
        $this->complaintsAllowed($request);
        $st = $request->validate(['status' => ['nullable', 'in:open,closed,all']])['status'] ?? 'open';
        $rows = Complaint::with(['employee', 'responder.employee'])->when($st === 'open', fn ($q) => $q->whereIn('status', ['received', 'reviewing']))->when($st === 'closed', fn ($q) => $q->where('status', 'closed'))
            ->latest('id')->limit(300)->get();
        return response()->json(['data' => $rows->map(fn ($c) => Complaints::row($c))]);
    }

    public function complaint(Request $request, int $id): JsonResponse
    {
        $this->complaintsAllowed($request);
        $d = $request->validate(['action' => ['required', 'in:review,close'], 'response' => ['nullable', 'string', 'max:4000']]);
        $c = Complaint::with(['employee', 'responder.employee'])->findOrFail($id);
        if ($d['action'] === 'review') $this->complaints->review($c, $request->user());
        else {
            if (mb_strlen(trim((string) ($d['response'] ?? ''))) < 10) throw ApiException::field('response', L::t('الرد المكتوب إلزامي (10 أحرف على الأقل).', 'A written reply is required (at least 10 characters).'), 'response_required');
            $this->complaints->close($c, $request->user(), $d['response']);
        }
        return response()->json(['data' => Complaints::row($c->fresh(['employee', 'responder.employee']))]);
    }

    private function complaintsAllowed(Request $request): void
    {
        if (Perm::scope($request->user(), 'discipline') !== 'all') abort(403);
    }

    private function find(Request $request, int $id): DisciplineCase
    {
        return DisciplineCase::with(self::WITH)->whereIn('employee_id', Directory::scoped($request->user(), 'discipline')->select('id'))->findOrFail($id);
    }
}
