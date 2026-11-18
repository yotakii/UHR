<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bonus;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Mission;
use App\Models\PayLine;
use App\Models\StoredFile;
use App\Services\Codes\Codes;
use App\Services\Missions\Bonuses;
use App\Services\Missions\Missions;
use App\Services\Missions\PayLines;
use App\Services\People\Directory;
use App\Services\Security\SalaryVault;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** «المهمات والسفر والمكافآت» (الجزء B — الدفعة 20) — البوابتان؛ الصلاحيات تُفحص في الخدمات */
class MissionController extends Controller
{
    public function __construct(private Missions $missions, private Bonuses $bonuses) {}

    private function query(Request $r)
    {
        $u = $r->user(); $view = $r->query('view', 'mine');
        $q = Mission::with(['type', 'category', 'employee', 'owner']);
        if ($view === 'mine') return $q->where(fn ($w) => $w->where('employee_id', $u->employee_id ?? 0)->orWhere('requested_by', $u->id));
        if ($view === 'owned') return $q->where('owner_id', $u->employee_id ?? 0);
        abort_unless(Perm::can($u, 'missions', 'view') || $u->hasRole('finance'), 403);
        $ids = Directory::scoped($u, 'missions')->pluck('id');
        return $q->where(fn ($w) => $w->whereIn('employee_id', $ids)->orWhere(fn ($x) => $x->whereNull('employee_id')->when(! Missions::canManage($u), fn ($y) => $y->where('requested_by', $u->id))));
    }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user()->loadMissing('roles');
        $q = $this->query($request);
        $st = $request->query('status', 'open');
        if ($st === 'open') $q->whereIn('status', Missions::OPEN); elseif ($st === 'settling') $q->whereIn('status', ['settling', 'done']); elseif ($st !== 'all') $q->where('status', $st);
        if ($t = $request->query('type')) $q->where('type_id', $t);
        if ($s = trim((string) $request->query('q'))) $q->where(fn ($w) => $w->where('ref_no', 'ilike', "%{$s}%")->orWhere('title', 'ilike', "%{$s}%"));
        $rows = $q->orderByDesc('id')->limit(300)->get();
        $all = $this->query($request->merge(['view' => $request->query('view', 'mine')]))->setEagerLoads([])->get(['id', 'status', 'due_on', 'travel', 'advance', 'close_due']);
        $today = now()->toDateString();
        return response()->json(['data' => $rows->map(fn ($m) => Missions::row($m, $u)),
            'dashboard' => ['open' => $all->whereIn('status', Missions::OPEN)->count(), 'pending' => $all->filter(fn ($m) => str_starts_with($m->status, 'pending'))->count(),
                'late' => $all->filter(fn ($m) => $m->due_on && $m->due_on->toDateString() < $today && in_array($m->status, ['assigned', 'in_progress', 'returned'], true))->count(),
                'travelling' => $all->filter(fn ($m) => $m->travel && ($m->travel['depart_on'] ?? '9') <= $today && ($m->travel['return_on'] ?? '0') >= $today && in_array($m->status, ['assigned', 'in_progress'], true))->count(),
                'unsettled' => $all->filter(fn ($m) => ($m->advance['status'] ?? null) === 'paid')->count(), 'settling' => $all->where('status', 'settling')->count(),
                'by_status' => $all->groupBy('status')->map->count()],
            'lookups' => ['types' => Codes::items('mission_types'), 'categories' => Codes::items('mission_categories'), 'cost_centers' => Codes::items('cost_centers'), 'governorates' => Codes::items('governorates'),
                'countries' => Codes::items('countries'), 'bonus_types' => Codes::items('bonus_types'), 'currencies' => Codes::items('currencies'),
                'departments' => \App\Models\Department::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
                'branches' => Location::where('active', true)->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
                'employees' => Directory::scoped($u, 'missions')->current()->orderBy('code')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
                'km_price' => SalaryVault::open($u) ? Missions::kmPrice() : null, 'stages' => Missions::cfg()['stages'], 'trainer_levels' => array_keys(Missions::cfg()['trainer_rates'])],
            'can' => ['create' => (bool) $u->employee_id || Perm::can($u, 'missions', 'create'), 'team' => Perm::can($u, 'missions', 'create'), 'manage' => Missions::canManage($u), 'finance' => Missions::isFinance($u),
                'bonus' => Perm::can($u, 'missions', 'create') && Perm::scope($u, 'missions') !== 'own', 'settings' => Perm::can($u, 'settings', 'edit'), 'reverse' => Perm::can($u, 'payroll', 'edit'), 'export' => Perm::can($u, 'missions', 'export')]]);
    }

    private function find(Request $r, int $id): Mission
    {
        $m = Mission::with(['type', 'employee'])->findOrFail($id);
        abort_unless(Missions::canSee($r->user()->loadMissing('roles'), $m), 404);
        return $m;
    }

    private function out(Request $r, Mission $m, int $code = 200): JsonResponse { return response()->json(['data' => Missions::row($m->refresh(), $r->user(), true)], $code); }

    public function show(Request $r, int $id): JsonResponse { return $this->out($r, $this->find($r, $id)); }
    public function store(Request $r): JsonResponse { return $this->out($r, $this->missions->save($r->user(), $r->all()), 201); }
    public function update(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        abort_unless($m->requested_by === $r->user()->id || Missions::canManage($r->user()), 403);
        return $this->out($r, $this->missions->save($r->user(), $r->all(), $m));
    }
    public function submit(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        abort_unless($m->requested_by === $r->user()->id || Missions::canManage($r->user()), 403);
        return $this->out($r, $this->missions->submit($m, $r->user()));
    }
    public function progress(Request $r, int $id): JsonResponse { return $this->out($r, $this->missions->progress($this->find($r, $id), $r->user(), $r->all())); }
    public function requestClose(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['report' => ['required', 'string', 'max:5000'], 'files' => ['nullable', 'array'], 'files.*' => ['integer']]);
        return $this->out($r, $this->missions->requestClose($this->find($r, $id), $r->user(), $d['report'], $this->ownFiles($r, $d['files'] ?? [])));
    }
    public function review(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['accept' => ['required', 'boolean'], 'rating' => ['nullable', 'integer', 'between:1,5'], 'note' => ['nullable', 'string', 'max:2000'], 'due_on' => ['nullable', 'date']]);
        return $this->out($r, $this->missions->review($this->find($r, $id), $r->user(), (bool) $d['accept'], $d['rating'] ?? null, $d['note'] ?? null, $d['due_on'] ?? null));
    }
    public function payAdvance(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['voucher_no' => ['required', 'string', 'max:60']]);
        return $this->out($r, $this->missions->payAdvance($this->find($r, $id), $r->user(), $d['voucher_no']));
    }
    public function settlement(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['depart_on' => ['nullable', 'date'], 'return_on' => ['nullable', 'date'], 'km' => ['nullable', 'numeric', 'min:0'], 'expenses' => ['nullable', 'array', 'max:40']]);
        $exp = array_map(fn ($x) => array_merge((array) $x, ['file_id' => in_array((int) ($x['file_id'] ?? 0), $this->ownFiles($r, [(int) ($x['file_id'] ?? 0)]), true) ? (int) $x['file_id'] : null]), $d['expenses'] ?? []);
        return $this->out($r, $this->missions->submitSettlement($this->find($r, $id), $r->user(), ['expenses' => $exp] + $d));
    }
    public function audit(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['approved' => ['nullable', 'array'], 'note' => ['nullable', 'string', 'max:2000']]);
        abort_unless(SalaryVault::open($r->user()), 403, L::t('تحقق من هويتك لعرض المبالغ.', 'Verify your identity to see the amounts.'));
        return $this->out($r, $this->missions->audit($this->find($r, $id), $r->user(), $d['approved'] ?? [], $d['note'] ?? null));
    }
    public function cancel(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['reason' => ['required', 'string', 'max:1000']]);
        return $this->out($r, $this->missions->cancel($this->find($r, $id), $r->user(), $d['reason']));
    }
    public function amend(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['depart_on' => ['required', 'date'], 'return_on' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000']]);
        return $this->out($r, $this->missions->amend($this->find($r, $id), $r->user(), $d['depart_on'], $d['return_on'], $d['reason']));
    }
    public function comment(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['text' => ['required', 'string', 'max:3000'], 'files' => ['nullable', 'array'], 'files.*' => ['integer']]);
        $this->missions->comment($this->find($r, $id), $r->user(), $d['text'], $this->ownFiles($r, $d['files'] ?? []));
        return $this->out($r, $this->find($r, $id));
    }

    /** رفع مرفق (فاتورة، إثبات، تقرير) — يُربط بالمهمة لاحقاً */
    public function upload(Request $r): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx']]);
        $f = $r->file('file');
        $path = $f->store('companies/'.\App\Support\Tenant::id().'/missions/'.now()->format('Y/m'), 'local');
        $s = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($f->getClientOriginalName(), 0, 255), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => $r->user()->id]);
        return response()->json(['id' => $s->id, 'name' => $s->name], 201);
    }

    private function ownFiles(Request $r, array $ids): array { return StoredFile::whereIn('id', array_filter($ids))->where('uploaded_by', $r->user()->id)->pluck('id')->all(); }

    // ——— المكافآت ———

    public function bonuses(Request $r): JsonResponse
    {
        $u = $r->user()->loadMissing('roles');
        $q = Bonus::with(['employee', 'type', 'requester'])->latest('id');
        if (Perm::can($u, 'missions', 'view') && Perm::scope($u, 'missions') !== 'own') $q->whereIn('employee_id', Directory::scoped($u, 'missions')->pluck('id'));
        else $q->where('employee_id', $u->employee_id ?? 0);
        if (($st = $r->query('status')) && $st !== 'all') $st === 'pending' ? $q->where('status', 'like', 'pending%') : $q->where('status', $st);
        return response()->json(['data' => $q->limit(300)->get()->map(fn ($b) => Bonuses::row($b, $u))]);
    }

    public function giveBonus(Request $r): JsonResponse
    {
        abort_unless(Perm::can($r->user(), 'missions', 'create') && Perm::scope($r->user(), 'missions') !== 'own', 403);
        $made = $this->bonuses->give($r->user(), $r->all());
        return response()->json(['data' => array_map(fn ($b) => Bonuses::row($b, $r->user()), $made)], 201);
    }

    // ——— بنود الراتب ———

    public function payLines(Request $r): JsonResponse
    {
        $u = $r->user();
        $q = PayLine::with('employee')->orderByDesc('id');
        if (Perm::can($u, 'payroll', 'view') && Perm::scope($u, 'payroll') === 'all') { if ($e = $r->query('employee')) $q->where('employee_id', $e); }
        else $q->where('employee_id', $u->employee_id ?? 0);
        if ($p = $r->query('period')) $q->where('period', $p);
        $rows = $q->limit(500)->get();
        return response()->json(['data' => $rows->map(fn ($l) => PayLines::row($l, SalaryVault::sees($u, $l->employee)) + ['employee' => ['id' => $l->employee->id, 'code' => $l->employee->code, 'ar' => $l->employee->name, 'en' => $l->employee->name_en]])]);
    }

    public function reverse(Request $r, int $id): JsonResponse
    {
        abort_unless(Perm::can($r->user(), 'payroll', 'edit'), 403);
        $d = $r->validate(['reason' => ['required', 'string', 'max:1000']]);
        $n = app(PayLines::class)->reverse(PayLine::findOrFail($id), $r->user(), $d['reason']);
        return response()->json(['id' => $n->id], 201);
    }

    // ——— التقارير والإعدادات ———

    public function reports(Request $r): JsonResponse
    {
        $u = $r->user(); abort_unless(Perm::can($u, 'missions', 'view') && Perm::scope($u, 'missions') !== 'own', 403);
        $from = $r->query('from') ?: now()->startOfYear()->toDateString(); $to = $r->query('to') ?: now()->toDateString();
        $ms = Mission::with(['type', 'employee.location', 'employee.department'])->whereIn('employee_id', Directory::scoped($u, 'missions')->pluck('id'))->whereBetween('created_at', [$from, $to.' 23:59:59'])->get();
        $show = SalaryVault::open($u);
        $grp = fn ($key) => $ms->groupBy($key)->map(fn ($g, $k) => ['key' => $k ?: '—', 'total' => $g->count(), 'done' => $g->whereIn('status', ['done', 'settling', 'posted', 'closed'])->count(),
            'late' => $g->filter(fn ($m) => $m->due_on && $m->done_at && $m->done_at->toDateString() > $m->due_on->toDateString())->count()])->values();
        $travel = $ms->filter(fn ($m) => $m->travel)->map(fn ($m) => ['ref_no' => $m->ref_no, 'employee' => $m->employee?->name, 'destination' => trim(($m->travel['city'] ?? '').' '.($m->travel['governorate'] ?? $m->travel['country'] ?? '')),
            'days' => $m->estimate['days'] ?? null, 'estimate' => $show ? ($m->estimate['totals'] ?? []) : null, 'status' => $m->status])->values();
        $unsettled = Mission::with('employee')->whereIn('employee_id', Directory::scoped($u, 'missions')->pluck('id'))->whereRaw("advance->>'status' = 'paid'")->get()
            ->map(fn ($m) => ['ref_no' => $m->ref_no, 'employee' => $m->employee?->name, 'amount' => $show ? $m->advance['amount'] : null, 'currency' => $m->advance['currency'], 'days' => $m->close_due ? max(0, (int) $m->close_due->diffInDays(now(), false)) : 0]);
        // تقرير الشذوذ (FR-TM-031): فواتير عند الحد دائماً، تكرار الوجهة، فواتير مكررة
        $anom = [];
        foreach ($ms->filter(fn ($m) => $m->settlement)->groupBy('employee_id') as $g) {
            $atLimit = 0; $amts = [];
            foreach ($g as $m) foreach ($m->settlement['lines'] ?? [] as $l) { if (! empty($l['limit']) && abs(($l['claimed'] ?? 0) - $l['limit']) < 0.01) $atLimit++; }
            foreach ($g as $m) foreach ($m->settlement['expenses'] ?? [] as $x) $amts[] = $x['amount'].$x['currency'];
            $dest = $g->map(fn ($m) => $m->travel['governorate'] ?? $m->travel['country'] ?? null)->filter()->countBy()->filter(fn ($n) => $n >= 3);
            $e = $g->first()->employee?->name;
            if ($atLimit >= 3) $anom[] = ['employee' => $e, 'kind' => 'at_limit', 'n' => $atLimit];
            if ($dest->isNotEmpty()) $anom[] = ['employee' => $e, 'kind' => 'same_destination', 'n' => $dest->max()];
            if (count($amts) !== count(array_unique($amts))) $anom[] = ['employee' => $e, 'kind' => 'duplicate_invoice', 'n' => count($amts) - count(array_unique($amts))];
        }
        $ccNames = \App\Models\CodeItem::whereIn('id', $ms->pluck('cost_center_id')->filter()->unique())->get()->mapWithKeys(fn ($c) => [$c->id => $c->code.' — '.$c->name_ar]);
        $byCc = $ms->groupBy(fn ($m) => $ccNames[$m->cost_center_id] ?? '—')->map(fn ($g, $k) => ['key' => $k, 'total' => $g->count(), 'done' => $g->whereIn('status', ['done', 'settling', 'posted', 'closed'])->count(), 'late' => 0,
            'cost' => $show ? $g->reduce(function ($acc, $m) { foreach (($m->estimate['totals'] ?? []) as $c => $v) $acc[$c] = round(($acc[$c] ?? 0) + $v, 2); return $acc; }, []) : null])->values();
        return response()->json(['from' => $from, 'to' => $to, 'by_status' => $ms->groupBy('status')->map->count(), 'by_type' => $grp(fn ($m) => $m->type?->name_ar), 'by_branch' => $grp(fn ($m) => $m->employee?->location?->name_ar), 'by_cost_center' => $byCc,
            'by_employee' => $grp(fn ($m) => $m->employee?->name), 'travel' => $travel, 'unsettled' => $unsettled, 'anomalies' => $anom, 'masked' => ! $show,
            'lines' => $show ? PayLine::whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)])->whereIn('source_type', ['mission', 'bonus'])->selectRaw('element_code, pay_currency, sum(amount_pay) as total, count(*) n')->groupBy('element_code', 'pay_currency')->get() : null]);
    }

    /** دعوة عمل ضمن المهمة (FR-TM-026) */
    public function invite(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        $v = $r->validate(['guest_name' => ['required', 'string', 'min:3', 'max:160'], 'organization' => ['nullable', 'string', 'max:160'], 'nationality' => ['nullable', 'string', 'size:2'], 'passport' => ['nullable', 'string', 'max:40'],
            'purpose' => ['required', 'string', 'min:5', 'max:2000'], 'from_on' => ['required', 'date'], 'to_on' => ['required', 'date', 'after_or_equal:from_on']]);
        app(\App\Services\Missions\MissionExtras::class)->invite($m, $r->user(), $v);
        return $this->out($r, $m);
    }

    /** عرض التقويم (FR-TM-029): المهمات في شهر */
    public function calendar(Request $r): JsonResponse
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('month')) ? $r->query('month') : now()->format('Y-m');
        $from = $month.'-01'; $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $rows = $this->query($r)->where(fn ($q) => $q->where('starts_on', '<=', $to)->where(fn ($w) => $w->where('due_on', '>=', $from)->orWhereNull('due_on')))->whereNotIn('status', ['cancelled', 'rejected'])->limit(500)->get();
        return response()->json(['month' => $month, 'data' => $rows->map(fn ($m) => Missions::row($m, $r->user()))]);
    }

    /** من يمكن الإشارة إليه في تعليقات المهمة: المنفّذ والمسؤول ومقدّم الطلب والموارد البشرية */
    public function mentionable(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        $ids = array_filter([$m->employee_id, $m->owner_id]);
        $users = \App\Models\User::where('company_id', $r->user()->company_id)->where('active', true)
            ->where(fn ($q) => $q->whereIn('employee_id', $ids)->orWhere('id', $m->requested_by)->orWhereHas('roles', fn ($x) => $x->whereIn('key', ['hr_director', 'hr_officer', 'finance'])))->limit(40)->get();
        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->displayName(app()->getLocale())])]);
    }

    public function export(Request $r): BinaryFileResponse
    {
        $u = $r->user(); abort_unless(Perm::can($u, 'missions', 'export'), 403);
        $rows = $this->query($r->merge(['view' => 'all']))->orderByDesc('id')->limit(5000)->get();
        $path = tempnam(sys_get_temp_dir(), 'ms').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->addRow(Row::fromValues(['الرقم', 'العنوان', 'النوع', 'المنفّذ', 'الحالة', 'الإنجاز %', 'البدء', 'الموعد', 'الوجهة', 'الأيام'], (new Style())->setFontBold()));
        foreach ($rows as $m) $w->addRow(Row::fromValues([$m->ref_no, $m->title, $m->type?->name_ar, $m->employee?->name ?? '', $m->status, $m->progress, $m->starts_on?->toDateString() ?? '', $m->due_on?->toDateString() ?? '',
            $m->travel ? trim(($m->travel['city'] ?? '').' '.($m->travel['governorate'] ?? $m->travel['country'] ?? '')) : '', $m->estimate['days'] ?? '']));
        $w->close();
        Audit::record('missions.exported', null, null, ['count' => $rows->count()]);
        return response()->download($path, 'UHR-missions.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function print(Request $r, int $id): JsonResponse
    {
        $m = $this->find($r, $id);
        return response()->json(['data' => Missions::row($m, $r->user(), true), 'employee' => $m->employee ? ['code' => $m->employee->code, 'title' => ['ar' => $m->employee->title_ar, 'en' => $m->employee->title_en],
            'grade' => $m->employee->grade_id ? Codes::resolve('grades', $m->employee->grade_id)?->code : null] : null]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['settings' => Missions::cfg(), 'defaults' => Settings::DEFAULTS['missions'], 'grades' => Codes::items('grades'), 'currencies' => Codes::items('currencies'), 'types' => Codes::items('mission_types')]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        abort_unless(Perm::can($r->user(), 'settings', 'edit'), 403);
        $n = fn ($a, $b) => ['required', 'numeric', "between:{$a},{$b}"];
        $d = $r->validate([
            'rates' => ['required', 'array', 'min:1'], 'rates.*.grade' => ['required', 'string'], 'rates.*.valid_from' => ['required', 'date'],
            'rates.*.local' => $n(0, 1e9), 'rates.*.foreign' => $n(0, 1e9), 'rates.*.hotel_local' => $n(0, 1e9), 'rates.*.hotel_foreign' => $n(0, 1e9),
            'rates.*.local_currency' => ['required', 'string', 'size:3'], 'rates.*.foreign_currency' => ['required', 'string', 'size:3'], 'rates.*.hotel_local_currency' => ['required', 'string', 'size:3'], 'rates.*.hotel_foreign_currency' => ['required', 'string', 'size:3'],
            'rates.*.ticket' => ['required', 'in:economy,premium,business,first'], 'default_grade' => ['required', 'string'], 'fuel_price' => $n(0, 1e9), 'fuel_currency' => ['required', 'string', 'size:3'],
            'km_per_tank' => $n(1, 10000), 'depreciation_pct' => $n(0, 100), 'close_days' => $n(1, 60), 'cutoff_day' => $n(0, 31), 'installments' => $n(1, 24), 'half_travel_days' => ['required', 'boolean'],
            'block_unclosed' => ['required', 'boolean'], 'expense_cap' => $n(0, 1e9), 'expense_currency' => ['required', 'string', 'size:3'], 'stages' => ['required', 'array', 'min:1', 'max:10'],
            'stages.*.name' => ['required', 'string', 'max:60'], 'stages.*.en' => ['required', 'string', 'max:60'], 'stages.*.weight' => $n(0, 100), 'proof_types' => ['present', 'array'],
            'completion_bonus' => ['required', 'array'], 'completion_bonus.*' => $n(0, 1e9), 'trainer_rates' => ['required', 'array'], 'trainer_rates.*' => $n(0, 1e9), 'trainer_currency' => ['required', 'string', 'size:3'],
            'attendance_bonus' => $n(0, 1e9), 'attendance_bonus_currency' => ['required', 'string', 'size:3'], 'bonus_over_cap_role' => ['required', 'in:'.implode(',', Perm::ROLES)], 'bonus_monthly_cap' => $n(0, 1e9),
            'zones' => ['sometimes', 'array', 'max:20'], 'zones.*.key' => ['required', 'regex:/^[A-Za-z0-9_-]{1,12}$/', 'distinct'], 'zones.*.ar' => ['required', 'string', 'max:60'], 'zones.*.en' => ['nullable', 'string', 'max:60'],
            'zones.*.governorates' => ['present', 'array'], 'zones.*.countries' => ['present', 'array'], 'zones.*.factor' => ['required', 'numeric', 'between:0.1,10'],
            'hotel_cities' => ['sometimes', 'array', 'max:60'], 'hotel_cities.*.city' => ['nullable', 'string', 'max:80'], 'hotel_cities.*.country' => ['nullable', 'string', 'size:2'], 'hotel_cities.*.governorate' => ['nullable', 'string', 'max:6'],
            'hotel_cities.*.grades' => ['present', 'array'], 'hotel_cities.*.limit' => $n(0, 1e9), 'hotel_cities.*.currency' => ['required', 'string', 'size:3'],
            'ticket_over' => ['sometimes', 'in:warn,block'], 'trainer_fee_auto' => ['sometimes', 'boolean'], 'trainer_default_level' => ['sometimes', 'string', 'max:20'], 'rates_by_class' => ['sometimes', 'array'],
        ]);
        $grades = collect(Codes::items('grades', false))->pluck('code')->all();
        foreach ($d['rates'] as $row) if (! in_array($row['grade'], $grades, true)) throw ApiException::field('rates', L::t('درجة غير موجودة في الدليل.', 'Grade not in the codebook.'), 'invalid');
        if (! in_array($d['default_grade'], $grades, true)) throw ApiException::field('default_grade', L::t('درجة غير موجودة في الدليل.', 'Grade not in the codebook.'), 'invalid');
        if (array_sum(array_column($d['stages'], 'weight')) !== 100) throw ApiException::field('stages', L::t('مجموع أوزان المراحل 100.', 'Stage weights must add up to 100.'), 'invalid');
        $before = Missions::cfg(); $new = array_merge($before, $d, ['over_limit' => 'cap']);
        Settings::put('missions', $new);
        Audit::record('settings.missions', null, null, ['changed' => array_keys(array_filter($new, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH))]);
        return $this->settings();
    }
}
