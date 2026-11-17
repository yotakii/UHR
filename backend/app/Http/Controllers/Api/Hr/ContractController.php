<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAnnex;
use App\Models\ContractCase;
use App\Models\Employee;
use App\Services\Codes\Codes;
use App\Services\Contracts\ContractCases;
use App\Services\Contracts\Contracts;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «العقود والتجديد» (الجزء D — الدفعة 19): الرادار، الحالات، سجل العقود، الملاحق، التقارير، الإعدادات */
class ContractController extends Controller
{
    public function __construct(private Contracts $contracts, private ContractCases $cases) {}

    private function scopedIds(Request $r) { return Directory::scoped($r->user(), 'contracts')->pluck('id'); }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user(); $ids = $this->scopedIds($request);
        $f = $request->only(['dept', 'branch', 'type', 'category']);
        return response()->json(['radar' => Contracts::radar($u, $f),
            'counts' => ContractCase::whereIn('employee_id', $ids)->where('status', '!=', 'closed')->selectRaw('kind, status, count(*) n')->groupBy('kind', 'status')->get()
                ->map(fn ($r) => ['kind' => $r->kind, 'status' => $r->status, 'n' => (int) $r->n]),
            'departments' => \App\Models\Department::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'branches' => \App\Models\Location::where('active', true)->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'types' => Codes::items('contract_types'), 'categories' => Codes::items('worker_categories'),
            'employees' => Directory::scoped($u, 'contracts')->current()->orderBy('code')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
            'settings' => Contracts::cfg(), 'can' => ['create' => Perm::can($u, 'contracts', 'create'), 'edit' => Perm::can($u, 'contracts', 'edit'), 'decide' => Perm::can($u, 'contracts', 'decide'),
                'settings' => Perm::can($u, 'settings', 'edit'), 'export' => Perm::can($u, 'contracts', 'export')]]);
    }

    public function cases(Request $request): JsonResponse
    {
        $q = ContractCase::with(['employee.department', 'contract.type', 'evaluator'])->whereIn('employee_id', $this->scopedIds($request))
            ->when($request->query('kind'), fn ($x, $k) => $x->where('kind', $k));
        $st = $request->query('status', 'open');
        if ($st === 'open') $q->where('status', '!=', 'closed'); elseif ($st !== 'all') $q->where('status', $st);
        if ($s = trim((string) $request->query('q'))) $q->where(fn ($w) => $w->where('ref_no', 'ilike', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")));
        return response()->json(['data' => $q->orderBy('due_on')->limit(300)->get()->map(fn ($k) => ContractCases::row($k, $request->user()))]);
    }

    private function case(Request $r, int $id): ContractCase { return ContractCase::whereIn('employee_id', $this->scopedIds($r))->findOrFail($id); }
    private function contract(Request $r, int $id): Contract { return Contract::whereIn('employee_id', $this->scopedIds($r))->findOrFail($id); }

    public function showCase(Request $request, int $id): JsonResponse { return response()->json(['data' => ContractCases::row($this->case($request, $id), $request->user(), true)]); }

    public function openCase(Request $request): JsonResponse
    {
        $d = $request->validate(['contract_id' => ['required', 'integer'], 'kind' => ['required', 'in:renewal,probation']]);
        $k = $this->cases->open($this->contract($request, $d['contract_id']), $d['kind'], $request->user());
        return response()->json(['data' => ContractCases::row($k, $request->user(), true)], 201);
    }

    public function evaluate(Request $request, int $id): JsonResponse
    {
        $k = $this->cases->evaluate($this->case($request, $id)->load('employee.department'), $request->user(), $request->all());
        return response()->json(['data' => ContractCases::row($k, $request->user(), true)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'checks' => ['nullable', 'array'], 'note' => ['nullable', 'string', 'max:2000']]);
        $k = $this->cases->review($this->case($request, $id), $request->user(), (bool) $d['approve'], $d['checks'] ?? [], $d['note'] ?? null);
        return response()->json(['data' => ContractCases::row($k, $request->user(), true)]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'string'], 'contract_type_id' => ['nullable', 'integer'], 'months' => ['nullable', 'integer', 'between:0,120'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $k = $this->cases->decide($this->case($request, $id)->load(['employee', 'contract']), $request->user(), $d);
        return response()->json(['data' => ContractCases::row($k, $request->user(), true)]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer'], 'decision' => ['required', 'in:renew,convert,not_renew,confirm,terminate']]);
        $ids = ContractCase::whereIn('employee_id', $this->scopedIds($request))->whereIn('id', $d['ids'])->pluck('id')->all();
        return response()->json($this->cases->bulk($request->user(), $ids, $d['decision']));
    }

    public function contracts(Request $request): JsonResponse
    {
        $q = Contract::with(['employee', 'type'])->whereIn('employee_id', $this->scopedIds($request))
            ->when($request->query('status'), fn ($x, $s) => $s === 'live' ? $x->whereIn('status', Contract::LIVE) : $x->where('status', $s))
            ->when($request->query('type'), fn ($x, $t) => $x->where('contract_type_id', $t))
            ->when($request->query('employee'), fn ($x, $e) => $x->where('employee_id', $e));
        if ($s = trim((string) $request->query('q'))) $q->where(fn ($w) => $w->where('ref_no', 'ilike', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")));
        return response()->json(['data' => $q->orderByDesc('starts_on')->limit(500)->get()->map(fn ($c) => Contracts::row($c, $request->user()))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $c = $this->contract($request, $id)->load(['employee.department', 'employee.location', 'type', 'annexes']);
        return response()->json(['data' => Contracts::row($c, $request->user()) + ['body' => $c->body ?: Contracts::render($c, 'ar'), 'body_en' => Contracts::render($c, 'en'),
            'annexes' => $c->annexes->map(fn ($a) => ['id' => $a->id, 'ref_no' => $a->ref_no, 'kind' => $a->kind, 'effective_on' => $a->effective_on->toDateString(), 'text' => $a->text, 'status' => $a->status]),
            'history' => Contract::with('type')->where('employee_id', $c->employee_id)->orderByDesc('starts_on')->get()->map(fn ($x) => Contracts::row($x))]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'contract_type_id' => ['required', 'integer'], 'starts_on' => ['required', 'date'], 'months' => ['nullable', 'integer', 'between:0,120'],
            'ends_on' => ['nullable', 'date'], 'probation_months' => ['nullable', 'integer', 'between:0,12'], 'note' => ['nullable', 'string', 'max:1000']]);
        $e = Directory::scoped($request->user(), 'contracts')->current()->findOrFail($d['employee_id']);
        return response()->json(['data' => Contracts::row($this->contracts->create($request->user(), $e, $d), $request->user())], 201);
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['role' => ['required', 'string'], 'signature' => ['nullable', 'string']]);
        return response()->json(['data' => Contracts::row($this->contracts->sign($this->contract($request, $id), $request->user(), $d['role'], $d['signature'] ?? null), $request->user())]);
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        return response()->json(['data' => Contracts::row($this->contracts->uploadSigned($this->contract($request, $id), $request->user(), $request->file('file')), $request->user())]);
    }

    public function copy(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['key' => ['required', 'string'], 'status' => ['required', 'in:pending,done']]);
        return response()->json(['data' => Contracts::row($this->contracts->copy($this->contract($request, $id), $d['key'], $d['status'], $request->user()), $request->user())]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return response()->json(['data' => Contracts::row($this->contracts->cancel($this->contract($request, $id), $request->user(), $d['reason']), $request->user())]);
    }

    public function annex(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', 'string'], 'effective_on' => ['required', 'date'], 'job_id' => ['nullable', 'integer'], 'grade_id' => ['nullable', 'integer'], 'text' => ['required', 'string', 'max:4000']]);
        $a = $this->contracts->annex($this->contract($request, $id)->load('employee'), $request->user(), $d);
        return response()->json(['id' => $a->id, 'ref_no' => $a->ref_no, 'status' => $a->status], 201);
    }

    /** بيانات الطباعة: العقد، تقييم التجربة/التجديد، الخطاب، الملحق */
    public function print(Request $request, string $kind, int $id): JsonResponse
    {
        $lang = app()->getLocale();
        if ($kind === 'contract') {
            $c = $this->contract($request, $id)->load(['employee.department', 'employee.location', 'type']);
            return response()->json(['contract' => Contracts::row($c), 'body' => $lang === 'en' ? Contracts::render($c, 'en') : ($c->body ?: Contracts::render($c, 'ar')),
                'signatures' => array_map(fn ($s) => $s + ['image' => $s['file_id'] ?? null ? \App\Support\Signature::dataUrl(\App\Models\StoredFile::find($s['file_id'])) : null,
                    'name' => ($s['user_id'] ?? null) ? \App\Models\User::find($s['user_id'])?->displayName($lang) : null], $c->signatures ?? [])]);
        }
        if ($kind === 'annex') {
            $a = ContractAnnex::with(['contract.type', 'employee'])->whereIn('employee_id', $this->scopedIds($request))->findOrFail($id);
            return response()->json(['annex' => ['ref_no' => $a->ref_no, 'kind' => $a->kind, 'effective_on' => $a->effective_on->toDateString(), 'text' => $a->text], 'contract' => Contracts::row($a->contract)]);
        }
        $k = $this->case($request, $id);
        $row = ContractCases::row($k, $request->user(), true);
        if ($kind === 'letter') {
            $which = $request->query('which') ?: ($row['letters'][0] ?? null);
            if (! $which || ! in_array($which, $row['letters'], true)) throw new ApiException(L::t('لا خطاب لهذه الحالة بعد.', 'No letter for this case yet.'), 'no_letter', 404);
            return response()->json(['case' => $row, 'which' => $which, 'text' => ContractCases::letterText($k, $which, $lang)]);
        }
        return response()->json(['case' => $row]);
    }

    public function reports(Request $request): JsonResponse
    {
        $from = $request->query('from') ?: now()->startOfYear()->toDateString(); $to = $request->query('to') ?: now()->endOfYear()->toDateString();
        return response()->json(['from' => $from, 'to' => $to] + ContractCases::reports($from, $to));
    }

    public function settings(): JsonResponse
    {
        return response()->json(['settings' => Contracts::cfg(), 'defaults' => Settings::DEFAULTS['contracts'], 'types' => Codes::items('contract_types'), 'causes' => Settings::offboarding()['causes'],
            'signers' => collect(Contracts::SIGNERS)->map(fn ($v, $k) => ['key' => $k, 'ar' => $v[0], 'en' => $v[1]])->values(), 'copies' => collect(Contracts::COPIES)->map(fn ($v, $k) => ['key' => $k, 'ar' => $v[0], 'en' => $v[1]])->values()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $n = fn ($a, $b) => ['required', 'integer', "between:{$a},{$b}"];
        $d = $request->validate([
            'renewal_open_days' => $n(5, 180), 'probation_open_days' => $n(3, 90), 'manager_days' => $n(1, 60), 'hr_days' => $n(1, 60), 'radar_days' => ['required', 'array', 'min:1', 'max:6'], 'radar_days.*' => $n(1, 365),
            'default_months' => $n(1, 120), 'probation_months' => $n(0, 12), 'open_after_years' => $n(1, 40), 'min_rating_renew' => ['required', 'string'],
            'fixed_type' => ['required', 'string'], 'open_type' => ['required', 'string'], 'allow_extension' => ['required', 'boolean'], 'extension_max_months' => $n(1, 12),
            'exit_cause_not_renewed' => ['required', 'string'], 'exit_cause_probation' => ['required', 'string'], 'fail_last_day' => ['required', 'in:decision,probation_end'],
            'signers' => ['required', 'array', 'min:1'], 'signers.*' => ['in:'.implode(',', array_keys(Contracts::SIGNERS))], 'copies' => ['present', 'array'], 'copies.*' => ['in:'.implode(',', array_keys(Contracts::COPIES))],
            'sign_mode' => ['required', 'in:electronic,upload,both'], 'review_checks' => ['present', 'array'], 'review_checks.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'review_checks.*.ar' => ['required', 'string', 'max:120'], 'review_checks.*.en' => ['required', 'string', 'max:120'],
            'scale' => ['required', 'array', 'min:2', 'max:8'], 'scale.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,29}$/'], 'scale.*.ar' => ['required', 'string', 'max:60'], 'scale.*.en' => ['required', 'string', 'max:60'], 'scale.*.score' => $n(0, 10),
            'justify_other_than' => ['required', 'string'], 'items' => ['required', 'array', 'min:3', 'max:40'], 'items.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'items.*.ar' => ['required', 'string', 'max:160'], 'items.*.en' => ['required', 'string', 'max:160'],
            'bands' => ['required', 'array', 'min:2'], 'bands.*.from' => $n(0, 100), 'bands.*.grade' => ['required', 'string'], 'bands.*.decision' => ['required', 'in:confirm,decide,terminate'],
            'template_ar' => ['required', 'string', 'max:20000'], 'template_en' => ['required', 'string', 'max:20000'],
            'letters' => ['required', 'array'], 'letters.*.ar' => ['required', 'string', 'max:4000'], 'letters.*.en' => ['required', 'string', 'max:4000'],
        ]);
        $keys = array_column($d['scale'], 'key');
        if (count(array_unique($keys)) !== count($keys)) throw ApiException::field('scale', L::t('مفاتيح السلّم مكررة.', 'Duplicate scale keys.'), 'invalid');
        foreach (['min_rating_renew', 'justify_other_than'] as $k) if (! in_array($d[$k], $keys, true)) throw ApiException::field($k, L::t('اختر تقديراً من السلّم.', 'Pick a rating from the scale.'), 'invalid');
        foreach ($d['bands'] as $b) if (! in_array($b['grade'], $keys, true)) throw ApiException::field('bands', L::t('كل شريحة تشير إلى تقدير من السلّم.', 'Each band must point to a scale rating.'), 'invalid');
        if (! collect($d['bands'])->contains('from', 0)) throw ApiException::field('bands', L::t('يجب أن تبدأ إحدى الشرائح من 0.', 'One band must start at 0.'), 'invalid');
        if (count(array_unique(array_column($d['items'], 'key'))) !== count($d['items'])) throw ApiException::field('items', L::t('مفاتيح العناصر مكررة.', 'Duplicate item keys.'), 'invalid');
        foreach (['fixed_type', 'open_type'] as $k) if (! Codes::resolve('contract_types', $d[$k])) throw ApiException::field($k, L::t('نوع عقد غير موجود في الدليل.', 'Contract type not in the codebook.'), 'invalid');
        $causes = array_column(Settings::offboarding()['causes'], 'key');
        foreach (['exit_cause_not_renewed', 'exit_cause_probation'] as $k) if (! in_array($d[$k], $causes, true)) throw ApiException::field($k, L::t('سبب إنهاء غير معروف.', 'Unknown termination cause.'), 'invalid');
        $letters = array_intersect_key($d['letters'], Settings::DEFAULTS['contracts']['letters']);
        if (count($letters) !== 4) throw ApiException::field('letters', L::t('الخطابات الأربعة مطلوبة.', 'All four letters are required.'), 'invalid');
        $before = Contracts::cfg();
        $new = array_merge($before, $d, ['letters' => $letters, 'radar_days' => array_values(array_unique(array_map('intval', $d['radar_days'])))]);
        Settings::put('contracts', $new);
        Audit::record('settings.contracts', null, null, ['changed' => array_keys(array_filter($new, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH))]);
        return $this->settings();
    }
}
