<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Competency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PerformanceAward;
use App\Models\PerformanceCycle;
use App\Models\PerformanceForm;
use App\Models\PerformanceReview;
use App\Services\People\Directory;
use App\Services\Performance\Reviews;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M13 — الأداء وتقييم 360 (Performance في الديمو): الدورات، 9-Box والمعايرة، الاعتماد، واستحقاق المكافآت (D-28) */
class PerformanceController extends Controller
{
    private const WITH = ['employee.department', 'forms'];

    public function __construct(private Reviews $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $cycles = PerformanceCycle::with('department')->orderByDesc('starts_on')->orderByDesc('id')->get();
        $cycle = $request->integer('cycle') ? $cycles->firstWhere('id', $request->integer('cycle')) : ($cycles->firstWhere('status', 'open') ?? $cycles->first());
        $scope = Directory::scoped($u, 'performance')->select('id');
        $reviews = $cycle ? PerformanceReview::with(self::WITH)->where('performance_cycle_id', $cycle->id)->whereIn('employee_id', $scope)->get() : collect();
        $awards = $cycle ? PerformanceAward::with('employee')->whereIn('performance_review_id', $reviews->pluck('id'))->get() : collect();
        return response()->json(['cycles' => $cycles->map(fn ($c) => Reviews::cycleRow($c)), 'cycle' => $cycle ? Reviews::cycleRow($cycle) : null,
            'reviews' => $reviews->map(fn ($r) => Reviews::reviewRow($r))->sortByDesc('score')->values(),
            'awards' => $awards->map(fn ($a) => Reviews::awardRow($a))->values(),
            'competencies' => Reviews::competencies()->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en]),
            'config' => Reviews::config(), 'departments' => Department::orderBy('id')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'employees' => Perm::can($u, 'performance', 'edit') ? Employee::current()->orderBy('code')->get()->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'dept' => $e->department_id]) : [],
            'reject_min' => (int) Settings::policy('leave_rules')['reject_min'],
            'can' => ['create' => Perm::can($u, 'performance', 'create'), 'edit' => Perm::can($u, 'performance', 'edit'), 'decide' => Perm::can($u, 'performance', 'decide'),
                'export' => Perm::can($u, 'performance', 'export'), 'settings' => Perm::can($u, 'settings', 'edit')]]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $r = PerformanceReview::with(['employee.department', 'forms.rater', 'award.employee'])->whereIn('employee_id', Directory::scoped($request->user(), 'performance')->select('id'))->findOrFail($id);
        return response()->json(['data' => Reviews::reviewRow($r, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Reviews::cycleRow($this->reviews->save(null, $request->all(), $request->user())->load('department'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => Reviews::cycleRow($this->reviews->save(PerformanceCycle::findOrFail($id), $request->all(), $request->user())->load('department'))]);
    }

    public function open(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => Reviews::cycleRow($this->reviews->open(PerformanceCycle::findOrFail($id), $request->user())->load('department'))]);
    }

    public function remind(int $id): JsonResponse
    {
        return response()->json(['reminded' => $this->reviews->remind(PerformanceCycle::findOrFail($id))]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return response()->json(['result' => $this->reviews->approve(PerformanceCycle::findOrFail($id), $request->user())]);
    }

    public function place(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['performance' => ['required', 'integer', 'between:1,3'], 'potential' => ['required', 'integer', 'between:1,3'], 'note' => ['nullable', 'string', 'max:500']]);
        $r = $this->reviews->place($this->scoped($request, $id), $d['performance'], $d['potential'], $d['note'] ?? null, $request->user());
        return response()->json(['data' => Reviews::reviewRow($r->load(self::WITH))]);
    }

    public function addPeer(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer']]);
        $this->reviews->addPeer($this->scoped($request, $id), Employee::current()->findOrFail($d['employee_id']));
        return $this->review($request, $id);
    }

    public function removePeer(Request $request, int $id, int $form): JsonResponse
    {
        $this->reviews->removePeer(PerformanceForm::where('performance_review_id', $this->scoped($request, $id)->id)->findOrFail($form));
        return $this->review($request, $id);
    }

    public function decideAward(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000'], 'kind' => ['nullable', 'in:amount,points'], 'value' => ['nullable', 'numeric', 'min:0']]);
        $a = PerformanceAward::whereIn('employee_id', Directory::scoped($request->user(), 'performance')->select('id'))->findOrFail($id);
        return response()->json(['data' => Reviews::awardRow($this->reviews->decideAward($a, $request->user(), (bool) $d['approve'], $d)->load('employee'))]);
    }

    public function export(Request $request, int $id): BinaryFileResponse
    {
        $c = PerformanceCycle::findOrFail($id);
        $comps = Reviews::competencies();
        $rows = PerformanceReview::with(['employee.department', 'forms', 'award'])->where('performance_cycle_id', $c->id)->whereIn('employee_id', Directory::scoped($request->user(), 'performance')->select('id'))->get()->sortByDesc('score');
        $path = tempnam(sys_get_temp_dir(), 'prf').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('Performance')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(1));
        $bold = (new Style())->setFontBold();
        $w->addRow(Row::fromValues(array_merge(['الرقم', 'الموظف', 'الإدارة', 'النتيجة', 'الأداء', 'الإمكانات', 'معايرة يدوية', 'النماذج المرسلة'],
            $comps->map(fn ($x) => $x->name_ar)->all(), ['المكافأة', 'حالة المكافأة']), $bold));
        foreach ($rows as $r) {
            $f = $r->forms;
            $w->addRow(Row::fromValues(array_merge([$r->employee->code, $r->employee->name, $r->employee->department?->name_ar ?? '', $r->score ?? '', $r->performance ?? '', $r->potential ?? '', $r->calibrated ? 'نعم' : '',
                $f->where('status', 'submitted')->count().'/'.$f->count()], $comps->map(fn ($x) => $r->ratings[$x->id]['total'] ?? $r->ratings[(string) $x->id]['total'] ?? '')->all(),
                [$r->award ? ($r->award->points ? $r->award->points.' نقطة' : $r->award->amount.' '.$r->award->currency) : '', $r->award?->status ?? ''])));
        }
        $w->close();
        Audit::record('performance.export', $c, null, ['count' => $rows->count()]);
        return response()->download($path, "UHR-performance-{$c->id}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    // ── الإعدادات ──

    public function settings(): JsonResponse
    {
        return response()->json(['config' => Reviews::config(), 'defaults' => Settings::DEFAULTS['performance'],
            'competencies' => Competency::orderBy('sort')->orderBy('id')->get()->map(fn ($c) => ['id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'active' => (bool) $c->active])]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $cfg = self::validConfig($request->input('config'));
        $comps = $this->validCompetencies($request->input('competencies'));
        $before = ['config' => Reviews::config(), 'competencies' => Competency::orderBy('sort')->get(['id', 'name_ar', 'name_en', 'active'])->toArray()];
        DB::transaction(function () use ($cfg, $comps) {
            Settings::put('performance', $cfg);
            foreach ($comps as $i => $c) {
                $row = ['name_ar' => $c['name_ar'], 'name_en' => $c['name_en'], 'active' => $c['active'], 'sort' => $i + 1];
                if (! empty($c['id'])) Competency::whereKey($c['id'])->update($row); else Competency::create($row);
            }
        });
        // النتائج المفتوحة تتبع الأوزان والحدود الجديدة
        $n = 0;
        foreach (PerformanceReview::whereHas('cycle', fn ($q) => $q->where('status', 'open'))->get() as $r) { Reviews::recompute($r); $n++; }
        Audit::record('settings.performance', null, $before, ['config' => $cfg, 'competencies' => $comps, 'recomputed' => $n]);
        return response()->json(['recomputed' => $n] + $this->settings()->getData(true));
    }

    /** معاينة: النتيجة ومربع 9-Box الحالي والجديد لمراجعات الدورة المفتوحة قبل الحفظ */
    public function preview(Request $request): JsonResponse
    {
        $cfg = self::validConfig($request->input('config'));
        $rows = PerformanceReview::with('employee')->whereHas('cycle', fn ($q) => $q->where('status', 'open'))->whereNotNull('score')->orderByDesc('score')->limit(15)->get()
            ->map(function ($r) use ($cfg) {
                $new = Reviews::recompute(clone $r, $cfg, false);
                return ['id' => $r->id, 'name' => ['ar' => $r->employee->name, 'en' => $r->employee->name_en], 'current' => ['score' => $r->score, 'p' => $r->performance], 'new' => ['score' => $new->score, 'p' => $new->performance], 'calibrated' => $r->calibrated];
            });
        return response()->json(['data' => $rows]);
    }

    public static function validConfig(mixed $in): array
    {
        $d = Settings::DEFAULTS['performance'];
        $err = [];
        $w = [];
        foreach ($d['weights'] as $k => $v) {
            $x = $in['weights'][$k] ?? null;
            if (! is_numeric($x) || $x < 0 || $x > 100 || (int) $x != $x) $err["config.weights.{$k}"] = [L::t('الوزن عدد صحيح 0–100.', 'A weight is a whole number 0–100.')];
            $w[$k] = (int) $x;
        }
        if (! $err && array_sum($w) !== 100) $err['config.weights.manager'] = [L::t('مجموع الأوزان 100.', 'Weights must total 100.')];
        if (! $err && $w['manager'] === 0 && $w['self'] === 0 && $w['peers'] === 0) $err['config.weights.manager'] = [L::t('وزن واحد على الأقل.', 'At least one weight.')];
        $scale = $in['scale'] ?? null;
        if (! is_numeric($scale) || (int) $scale != $scale || $scale < 3 || $scale > 10) $err['config.scale'] = [L::t('السلّم من 3 إلى 10.', 'Scale 3 to 10.')];
        $peers = $in['peers'] ?? null;
        if (! is_numeric($peers) || (int) $peers != $peers || $peers < 0 || $peers > 10) $err['config.peers'] = [L::t('عدد الزملاء 0–10.', 'Peers 0–10.')];
        $hi = $in['high_from'] ?? null; $mid = $in['mid_from'] ?? null;
        if (! is_numeric($hi) || ! is_numeric($mid) || $mid < 1 || $hi <= $mid || ($scale && $hi > $scale)) $err['config.high_from'] = [L::t('حد «مرتفع» أكبر من «متوسط» وضمن السلّم.', '«High» must exceed «mid» and fit the scale.')];
        $bands = [];
        foreach (array_values((array) ($in['rewards'] ?? [])) as $i => $b) {
            $ok = is_numeric($b['from'] ?? null) && is_numeric($b['to'] ?? null) && $b['from'] <= $b['to'] && $b['from'] >= 0 && (! is_numeric($scale) || $b['to'] <= $scale)
                && in_array($b['kind'] ?? null, ['percent', 'amount', 'points'], true) && is_numeric($b['value'] ?? null) && $b['value'] > 0 && ($b['kind'] !== 'percent' || $b['value'] <= 100);
            if (! $ok) { $err["config.rewards.{$i}"] = [L::t('بند غير صالح: من ≤ إلى ضمن السلّم، والقيمة موجبة (النسبة ≤ 100).', 'Invalid band: from ≤ to within the scale, positive value (percent ≤ 100).')]; continue; }
            $bands[] = ['from' => round((float) $b['from'], 1), 'to' => round((float) $b['to'], 1), 'kind' => $b['kind'], 'value' => (float) $b['value']];
        }
        usort($bands, fn ($a, $b) => $a['from'] <=> $b['from']);
        for ($i = 1; $i < count($bands); $i++) if ($bands[$i]['from'] <= $bands[$i - 1]['to']) $err['config.rewards'] = [L::t('البنود متداخلة.', 'Bands overlap.')];
        if ($err) throw ValidationException::withMessages($err);
        return ['weights' => $w, 'scale' => (int) $scale, 'peers' => (int) $peers, 'high_from' => round((float) $hi, 1), 'mid_from' => round((float) $mid, 1), 'rewards' => $bands];
    }

    private function validCompetencies(mixed $in): array
    {
        $out = [];
        $err = [];
        foreach (array_values((array) $in) as $i => $c) {
            $ar = trim((string) ($c['name_ar'] ?? '')); $en = trim((string) ($c['name_en'] ?? ''));
            if (mb_strlen($ar) < 2 || mb_strlen($en) < 2) { $err["competencies.{$i}"] = [L::t('اسم الكفاءة بالعربية والإنكليزية.', 'Name the competency in both languages.')]; continue; }
            if (! empty($c['id']) && ! Competency::whereKey($c['id'])->exists()) { $err["competencies.{$i}"] = [L::t('كفاءة غير موجودة.', 'Unknown competency.')]; continue; }
            $out[] = ['id' => $c['id'] ?? null, 'name_ar' => $ar, 'name_en' => $en, 'active' => (bool) ($c['active'] ?? true)];
        }
        if (count(array_filter($out, fn ($c) => $c['active'])) < 3) $err['competencies'] = [L::t('ثلاث كفاءات فعّالة على الأقل.', 'At least three active competencies.')];
        if ($err) throw ValidationException::withMessages($err);
        return $out;
    }

    private function scoped(Request $request, int $id): PerformanceReview
    {
        return PerformanceReview::whereIn('employee_id', Directory::scoped($request->user(), 'performance')->select('id'))->findOrFail($id);
    }
}
