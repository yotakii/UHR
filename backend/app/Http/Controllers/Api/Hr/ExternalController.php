<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\ExpertRating;
use App\Models\External;
use App\Models\PaymentRequest;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\VolunteerLog;
use App\Models\VolunteerOpportunity;
use App\Services\Codes\Codes;
use App\Services\Externals\Externals;
use App\Services\Externals\Payments;
use App\Services\Externals\Roster;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * تبويبة «غير الموظفين» (الدفعة 22): المتطوعون والعقود القصيرة والاستشاريون، قائمة الخبراء وتكليفاتهم،
 * طلبات الدفع للمالية، فرص التطوع، التقارير والإعدادات. الأتعاب والتقييمات والملاحظات لأدوار محددة فقط (AC-RS-03).
 */
class ExternalController extends Controller
{
    public function __construct(private Externals $ext, private Roster $roster, private Payments $pay) {}

    /** المدير المباشر يرى من يشرف عليهم فقط */
    private function scope(Request $r)
    {
        $u = $r->user();
        return External::query()->when(Perm::scope($u, 'externals') !== 'all', fn ($q) => $q->where(fn ($w) => $w->where('supervisor_id', $u->employee_id ?? 0)
            ->orWhereHas('engagements', fn ($g) => $g->where('supervisor_id', $u->employee_id ?? 0))));
    }

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['tab' => ['nullable', 'in:people,experts'], 'kind' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', 'integer'], 'years' => ['nullable', 'integer'], 'level' => ['nullable', 'string'], 'country' => ['nullable', 'string', 'size:2'], 'city' => ['nullable', 'string'],
            'language' => ['nullable', 'string'], 'available' => ['nullable', 'boolean'], 'rating' => ['nullable', 'numeric'], 'all' => ['nullable', 'boolean']]);
        $u = $r->user(); $fees = Externals::canFees($u);
        if (($f['tab'] ?? 'people') === 'experts') {
            $rows = Roster::search($f + ['all' => ! empty($f['all']) || ! empty($f['status'])])->with(['category'])->limit(300)->get();
        } else {
            $rows = $this->scope($r)->whereNotIn('kind', ['expert', 'consultant'])->with(['category', 'supervisor:id,name,name_en', 'location', 'department'])
                ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$v}%")->orWhere('name_en', 'ilike', "%{$v}%")->orWhere('ref', 'ilike', "%{$v}%")->orWhere('email', 'ilike', "%{$v}%")))
                ->latest('id')->limit(300)->get();
        }
        $hours = VolunteerLog::where('volunteer_logs.status', 'approved')->join('engagements', 'engagements.id', '=', 'volunteer_logs.engagement_id')->selectRaw('engagements.external_id, sum(hours) h')->groupBy('engagements.external_id')->pluck('h', 'external_id');
        return response()->json(['data' => $rows->map(fn (External $x) => self::row($x, $fees) + ['hours' => (float) ($hours[$x->id] ?? 0)]),
            'counts' => ['volunteers' => External::where('kind', 'volunteer')->where('status', 'active')->count(), 'pending_logs' => VolunteerLog::where('status', 'pending')->count(),
                'experts' => External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->count(), 'vetting' => External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['applicant', 'vetting'])->count(),
                'assignments' => Assignment::whereIn('status', Roster::LIVE)->count(), 'payments' => PaymentRequest::whereIn('status', ['pending', 'approved'])->count()],
            'lookups' => self::lookups(), 'can' => self::can($u)]);
    }

    public static function can(User $u): array
    {
        return ['create' => Perm::can($u, 'externals', 'create'), 'manage' => Externals::canManage($u), 'decide' => Perm::can($u, 'externals', 'decide'), 'fees' => Externals::canFees($u),
            'pay' => Payments::canDecide($u), 'export' => Perm::can($u, 'externals', 'export'), 'settings' => Perm::can($u, 'settings', 'edit')];
    }

    public static function lookups(): array
    {
        $cats = array_values(array_filter(Codes::items('worker_categories'), fn ($c) => ! ($c['attrs']['payroll'] ?? false)));
        return ['categories' => $cats, 'specialties' => Codes::items('specialties'), 'countries' => Codes::items('countries'), 'currencies' => Codes::items('currencies'),
            'departments' => \App\Models\Department::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'branches' => \App\Models\Location::where('active', true)->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'employees' => \App\Models\Employee::current()->orderBy('name')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
            'jobs' => \App\Models\JobOpening::where('status', 'open')->get(['id', 'code', 'title_ar', 'title_en'])->map(fn ($j) => ['id' => $j->id, 'code' => $j->code, 'ar' => $j->title_ar, 'en' => $j->title_en]),
            'test' => Externals::TEST, 'relations' => Externals::RELATIONS, 'levels' => Roster::LEVELS, 'cfg' => array_intersect_key(Externals::cfg(), array_flip(['max_months', 'max_weekly_hours', 'relation_threshold', 'replaces_max_months', 'pledges', 'expense_cap', 'expense_currency', 'shortlist_min']))];
    }

    public static function row(External $x, bool $fees): array
    {
        $x->loadMissing('category');
        return ['id' => $x->id, 'ref' => $x->ref, 'kind' => $x->kind, 'category' => $x->category ? ['code' => $x->category->code, 'ar' => $x->category->name_ar, 'en' => $x->category->name_en] : null,
            'entity' => $x->entity, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name, 'email' => $x->email, 'phone' => $x->phone, 'country' => $x->country, 'city' => $x->city, 'status' => $x->status,
            'specialties' => $x->specialties ?? [], 'years' => $x->years, 'level' => $x->level, 'languages' => $x->languages ?? [], 'available' => $x->available, 'available_from' => $x->available_from?->toDateString(),
            'avg_rating' => $x->avg_rating, 'ratings' => $x->ratings, 'last_engaged_at' => $x->last_engaged_at?->toIso8601String(), 'review_due' => $x->review_due?->toDateString(),
            'supervisor' => $x->relationLoaded('supervisor') && $x->supervisor ? ['ar' => $x->supervisor->name, 'en' => $x->supervisor->name_en] : null,
            'rate' => $fees ? $x->rate : null, 'rate_unit' => $fees ? $x->rate_unit : null, 'rate_currency' => $fees ? $x->rate_currency : null, 'fees_hidden' => ! $fees, 'source' => $x->source, 'has_account' => (bool) $x->user_id];
    }

    public function show(Request $r, int $id): JsonResponse
    {
        $x = $this->scope($r)->with(['category', 'supervisor:id,name,name_en', 'location', 'department'])->findOrFail($id);
        $u = $r->user(); $fees = Externals::canFees($u);
        $eng = Engagement::where('external_id', $x->id)->with(['supervisor:id,name,name_en'])->latest('id')->get();
        $asg = Assignment::where('external_id', $x->id)->with(['deliverables', 'rating'])->latest('id')->get();
        return response()->json(['data' => self::row($x, $fees) + [
            'gender' => $x->gender, 'dob' => $x->dob?->toDateString(), 'national_id' => $x->national_id, 'nationality' => $x->nationality, 'emergency' => $x->emergency, 'areas' => $x->areas ?? [],
            'availability' => $x->availability, 'sectors' => $x->sectors ?? [], 'keywords' => $x->keywords ?? [], 'projects' => $x->projects, 'work_modes' => $x->work_modes ?? [], 'cv_file_id' => $x->cv_file_id,
            'documents' => $x->documents ?? [], 'notes' => $fees ? $x->notes : null, 'status_note' => $x->status_note, 'worker_category_id' => $x->worker_category_id,
            'location_id' => $x->location_id, 'department_id' => $x->department_id, 'supervisor_id' => $x->supervisor_id, 'converted_candidate_id' => $x->converted_candidate_id,
            'engagements' => $eng->map(fn ($g) => self::engagement($g, $u)),
            'assignments' => $asg->map(fn ($a) => self::assignment($a, $u)),
            'payments' => $fees || Payments::canDecide($u) ? PaymentRequest::where('external_id', $x->id)->latest('id')->get()->map(fn ($p) => self::payment($p)) : [],
        ]]);
    }

    public static function engagement(Engagement $g, User $u): array
    {
        $cur = Workflow::current($g);
        $logs = VolunteerLog::where('engagement_id', $g->id)->latest('date')->latest('id')->limit(60)->get();
        return ['id' => $g->id, 'ref' => $g->ref, 'title' => $g->title, 'tasks' => $g->tasks, 'starts_on' => $g->starts_on->toDateString(), 'ends_on' => $g->ends_on->toDateString(), 'weekly_hours' => $g->weekly_hours,
            'relation' => $g->relation, 'relation_score' => $g->relation_score, 'relation_test' => $g->relation_test, 'justification' => $g->justification, 'replaces_position' => $g->replaces_position, 'replaces_employee_id' => $g->replaces_employee_id, 'relation_employee_id' => $g->relation_employee_id, 'mission_id' => $g->mission_id, 'project' => $g->project,
            'high' => Externals::high($g), 'fee_model' => $g->fee_model, 'amount' => Externals::canFees($u) ? $g->amount : null, 'currency' => $g->currency, 'expense_cap' => $g->expense_cap, 'tax_treatment' => $g->tax_treatment,
            'status' => $g->status, 'body' => $g->body, 'pledges' => $g->pledges, 'signed_at' => $g->signed_at?->toIso8601String(), 'onboarding' => $g->onboarding ?? [], 'evaluation' => $g->evaluation,
            'certificate_no' => $g->certificate_no, 'verify_code' => $g->verify_code, 'stage_type' => $g->stage_type, 'required_hours' => $g->required_hours, 'stations' => $g->stations, 'stage_eval' => $g->stage_eval, 'supervisor' => $g->supervisor ? ['id' => $g->supervisor->id, 'ar' => $g->supervisor->name, 'en' => $g->supervisor->name_en] : null,
            'supervisor_id' => $g->supervisor_id, 'department_id' => $g->department_id, 'location_id' => $g->location_id, 'days' => $g->days,
            'hours' => Externals::hours($g), 'pending_hours' => (float) $logs->where('status', 'pending')->sum('hours'),
            'logs' => $logs->map(fn ($l) => ['id' => $l->id, 'date' => $l->date->toDateString(), 'hours' => $l->hours, 'activity' => $l->activity, 'via' => $l->via, 'status' => $l->status, 'note' => $l->note]),
            'steps' => Workflow::steps($g), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($u, $cur, $g) : false,
            'can' => ['edit' => in_array($g->status, ['draft', 'rejected'], true) && Externals::canManage($u), 'approve_logs' => Externals::isSupervisor($u, $g) || Externals::canManage($u),
                'end' => $g->status === 'active' && (Externals::isSupervisor($u, $g) || Externals::canManage($u))]];
    }

    public static function assignment(Assignment $a, User $u): array
    {
        $fees = Externals::canFees($u);
        $a->loadMissing(['deliverables', 'rating', 'expert']);
        $short = External::whereIn('id', array_column($a->shortlist ?? [], 'external_id'))->get()->keyBy('id');
        return ['id' => $a->id, 'ref' => $a->ref, 'title' => $a->title, 'tor' => $a->tor, 'department_id' => $a->department_id, 'starts_on' => $a->starts_on?->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
            'budget' => $fees ? $a->budget : null, 'total_fee' => $fees ? $a->total_fee : null, 'currency' => $a->currency, 'tax_treatment' => $fees ? $a->tax_treatment : null, 'status' => $a->status,
            'expert' => $a->expert ? ['id' => $a->expert->id, 'ar' => $a->expert->name, 'en' => $a->expert->name_en ?: $a->expert->name, 'ref' => $a->expert->ref] : null,
            'shortlist' => array_map(fn ($s) => $s + ['ar' => $short[$s['external_id']]->name ?? '—', 'en' => ($short[$s['external_id']] ?? null)?->name_en ?: ($short[$s['external_id']]->name ?? '—'), 'rating' => $short[$s['external_id']]->avg_rating ?? null], $a->shortlist ?? []),
            'selection_reason' => $a->selection_reason, 'relation_score' => $a->relation_score, 'pledges' => $a->pledges, 'contracted_at' => $a->contracted_at?->toIso8601String(), 'contract_file_id' => $a->contract_file_id,
            'deliverables' => $a->deliverables->map(fn (Deliverable $d) => ['id' => $d->id, 'title' => $d->title, 'due_on' => $d->due_on?->toDateString(), 'payment' => $fees ? $d->payment : null,
                'status' => $d->status, 'file_id' => $d->file_id, 'submitted_at' => $d->submitted_at?->toIso8601String(), 'note' => $d->note, 'late' => $d->due_on && $d->status === 'pending' && $d->due_on->isPast()]),
            'rating' => $a->rating ? ['score' => $a->rating->score, 'quality' => $a->rating->quality, 'timeliness' => $a->rating->timeliness, 'communication' => $a->rating->communication, 'value' => $a->rating->value,
                'rehire' => $a->rating->rehire, 'notes' => $fees ? $a->rating->notes : null] : null, 'fees_hidden' => ! $fees];
    }

    public static function payment(PaymentRequest $p): array
    {
        $p->loadMissing('external');
        return ['id' => $p->id, 'ref' => $p->ref, 'external' => $p->external ? ['id' => $p->external->id, 'ar' => $p->external->name, 'en' => $p->external->name_en ?: $p->external->name] : null, 'source_type' => $p->source_type,
            'description' => $p->description, 'amount' => $p->amount, 'currency' => $p->currency, 'file_id' => $p->file_id, 'status' => $p->status, 'finance_ref' => $p->finance_ref, 'note' => $p->note,
            'created_at' => $p->created_at?->toIso8601String(), 'paid_at' => $p->paid_at?->toIso8601String()];
    }

    private function input(Request $r): array
    {
        return $r->validate(['worker_category_id' => ['required'], 'entity' => ['nullable', 'in:person,firm'], 'name' => ['required', 'string', 'max:160'], 'name_en' => ['nullable', 'string', 'max:160'],
            'gender' => ['nullable', 'in:m,f'], 'dob' => ['nullable', 'date'], 'national_id' => ['nullable', 'string', 'max:40'], 'nationality' => ['nullable', 'string', 'size:2'], 'email' => ['nullable', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'], 'country' => ['nullable', 'string', 'size:2'], 'city' => ['nullable', 'string', 'max:80'], 'emergency' => ['nullable', 'array'], 'areas' => ['nullable', 'array'],
            'availability' => ['nullable', 'array'], 'specialties' => ['nullable', 'array'], 'sectors' => ['nullable', 'array'], 'keywords' => ['nullable', 'array'], 'languages' => ['nullable', 'array'],
            'years' => ['nullable', 'integer', 'between:0,60'], 'level' => ['nullable', 'in:'.implode(',', Roster::LEVELS)], 'projects' => ['nullable', 'string', 'max:3000'], 'available' => ['nullable', 'in:now,from,no'],
            'available_from' => ['nullable', 'date'], 'work_modes' => ['nullable', 'array'], 'rate' => ['nullable', 'numeric', 'min:0'], 'rate_unit' => ['nullable', 'in:hour,day'], 'rate_currency' => ['nullable', 'string', 'size:3'],
            'location_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'supervisor_id' => ['nullable', 'integer'], 'cv_file_id' => ['nullable', 'integer'], 'documents' => ['nullable', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:60'], 'documents.*.file_id' => ['required_with:documents', 'integer'], 'documents.*.valid_to' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:3000']],
            [], ['name' => L::t('الاسم', 'Name'), 'email' => L::t('البريد', 'Email'), 'worker_category_id' => L::t('الفئة', 'Category')]);
    }

    public function store(Request $r): JsonResponse
    {
        $x = $this->ext->save($this->input($r), $r->user());
        return response()->json(['id' => $x->id], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $x = External::findOrFail($id);
        $this->ext->save($this->input($r), $r->user(), $x);
        return $this->show($r, $id);
    }

    public function status(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['status' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->ext->setStatus(External::findOrFail($id), $v['status'], $v['note'] ?? null, $r->user());
        return $this->show($r, $id);
    }

    public function account(Request $r, int $id): JsonResponse
    {
        $x = External::findOrFail($id);
        $u = $this->ext->account($x);
        if (! $u) throw new ApiException(L::t('تعذّر إنشاء الحساب: البريد غير مسجّل أو مستخدم لحساب آخر.', 'Could not create the account: the email is missing or used by another account.'), 'no_account', 422);
        return $this->show($r, $id);
    }

    // ——— التكليف ———

    private function engInput(Request $r): array
    {
        return $r->validate(['title' => ['required', 'string', 'max:200'], 'tasks' => ['nullable', 'string', 'max:3000'], 'department_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'supervisor_id' => ['required', 'integer'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date'], 'weekly_hours' => ['required', 'integer', 'between:1,60'], 'days' => ['nullable', 'array'],
            'relation' => ['required', 'in:replaces,alongside,independent'], 'replaces_employee_id' => ['nullable', 'integer'], 'relation_employee_id' => ['nullable', 'integer'], 'mission_id' => ['nullable', 'integer'], 'project' => ['nullable', 'string', 'max:160'], 'replaces_position' => ['nullable', 'string', 'max:160'],
            'relation_test' => ['nullable', 'array'], 'justification' => ['nullable', 'string', 'max:2000'], 'fee_model' => ['nullable', 'in:none,lump,daily,monthly,expenses'],
            'amount' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'expense_cap' => ['nullable', 'numeric', 'min:0'], 'tax_treatment' => ['nullable', 'string', 'max:255'],
            'stage_type' => ['nullable', 'in:paid,free,fee'], 'required_hours' => ['nullable', 'integer', 'between:1,3000'], 'stations' => ['nullable', 'array', 'max:12']],
            ['supervisor_id.required' => L::t('المشرف إلزامي.', 'A supervisor is required.')]);
    }

    public function engage(Request $r, int $id): JsonResponse
    {
        $g = $this->ext->engage(External::findOrFail($id), $this->engInput($r), $r->user());
        return response()->json(['data' => self::engagement($g, $r->user())], 201);
    }

    public function updateEngagement(Request $r, int $id): JsonResponse
    {
        $g = Engagement::findOrFail($id);
        $g = $this->ext->engage(External::findOrFail($g->external_id), $this->engInput($r), $r->user(), $g);
        return response()->json(['data' => self::engagement($g, $r->user())]);
    }

    public function submit(Request $r, int $id): JsonResponse
    {
        return response()->json(['data' => self::engagement($this->ext->submit(Engagement::findOrFail($id), $r->user()), $r->user())]);
    }

    public function decide(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        return response()->json(['data' => self::engagement($this->ext->decide(Engagement::findOrFail($id), $r->user(), $v['approve'], $v['note'] ?? null), $r->user())]);
    }

    /** توقيع ورقي بإدخال الموارد البشرية (رفع الاتفاقية الموقّعة) */
    public function sign(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['pledges' => ['required', 'array'], 'file_id' => ['nullable', 'integer'], 'signature' => ['nullable', 'string']]);
        return response()->json(['data' => self::engagement($this->ext->sign(Engagement::findOrFail($id), $r->user(), $v), $r->user())]);
    }

    public function checklist(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['i' => ['required', 'integer', 'min:0'], 'done' => ['required', 'boolean']]);
        return response()->json(['data' => self::engagement($this->ext->checklist(Engagement::findOrFail($id), $v['i'], $v['done'], $r->user()), $r->user())]);
    }

    public function log(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['date' => ['required', 'date'], 'hours' => ['required', 'numeric', 'min:0.25', 'max:16'], 'activity' => ['required', 'string', 'min:3', 'max:500']]);
        $res = $this->ext->log(Engagement::findOrFail($id), $v, $r->user());
        return response()->json(['ok' => true, 'warning' => $res['warning']], 201);
    }

    public function approveLogs(Request $r): JsonResponse
    {
        $v = $r->validate(['ids' => ['required', 'array'], 'approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        return response()->json(['count' => $this->ext->approveLogs($v['ids'], $v['approve'], $v['note'] ?? null, $r->user())]);
    }

    /** ساعات بانتظار المصادقة — للمشرف أو الموارد البشرية */
    public function pendingLogs(Request $r): JsonResponse
    {
        $u = $r->user();
        $rows = VolunteerLog::with('engagement.external')->where('status', 'pending')
            ->when(! Externals::canManage($u), fn ($q) => $q->whereHas('engagement', fn ($g) => $g->where('supervisor_id', $u->employee_id ?? 0)))->orderBy('date')->limit(200)->get();
        return response()->json(['data' => $rows->map(fn ($l) => ['id' => $l->id, 'date' => $l->date->toDateString(), 'hours' => $l->hours, 'activity' => $l->activity,
            'external' => ['id' => $l->engagement->external_id, 'ar' => $l->engagement->external->name, 'en' => $l->engagement->external->name_en ?: $l->engagement->external->name], 'ref' => $l->engagement->ref])]);
    }

    public function expense(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['amount' => ['required', 'numeric', 'min:1'], 'description' => ['required', 'string', 'max:255'], 'file_id' => ['required', 'integer']]);
        return response()->json(['data' => self::payment($this->ext->expense(Engagement::findOrFail($id), $v, $r->user()))], 201);
    }

    public function end(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['rating' => ['required', 'integer', 'between:1,5'], 'note' => ['nullable', 'string', 'max:1000'], 'early' => ['nullable', 'boolean'], 'skills' => ['nullable', 'array'], 'recommend_hire' => ['nullable', 'boolean']]);
        return response()->json(['data' => self::engagement($this->ext->end(Engagement::findOrFail($id), $r->user(), $v, (bool) ($v['early'] ?? false)), $r->user())]);
    }

    public function certificate(Request $r, int $id): JsonResponse
    {
        $g = Engagement::findOrFail($id);
        $this->scope($r)->findOrFail($g->external_id);
        if (! $g->verify_code) throw new ApiException(L::t('الشهادة تصدر عند انتهاء التكليف.', 'The certificate is issued when the engagement ends.'), 'not_ended', 409);
        return response()->json(['data' => Externals::certificate($g)]);
    }

    public function agreement(Request $r, int $id): JsonResponse
    {
        $g = Engagement::findOrFail($id); $x = External::findOrFail($g->external_id);
        return response()->json(['data' => ['ref' => $g->ref, 'body' => $g->body ?: Externals::render($g, $x, 'ar')."\n\n———\n\n".Externals::render($g, $x, 'en'), 'signed_at' => $g->signed_at?->toIso8601String(), 'signature_file_id' => $g->signature_file_id,
            'name' => ['ar' => $x->name, 'en' => $x->name_en ?: $x->name], 'pledges' => $g->pledges]]);
    }

    public function convert(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['job_id' => ['required', 'integer']]);
        $c = $this->ext->toCandidate(External::findOrFail($id), $v['job_id'], $r->user());
        return response()->json(['candidate_id' => $c->id]);
    }

    public function upload(Request $r): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx,pptx,zip']]);
        return response()->json(self::store_file($r->file('file'), $r->user()?->id), 201);
    }

    public static function store_file(\Illuminate\Http\UploadedFile $f, ?int $by): array
    {
        $path = $f->store('companies/'.\App\Support\Tenant::id().'/externals/'.now()->format('Y/m'), 'local');
        $s = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($f->getClientOriginalName(), 0, 255), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => $by]);
        return ['id' => $s->id, 'name' => $s->name];
    }

    // ——— الخبراء والتكليفات ———

    public function assignments(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => ['nullable', 'string'], 'q' => ['nullable', 'string']]);
        $rows = Assignment::with(['expert', 'deliverables', 'rating'])->when($f['status'] ?? null, fn ($q, $v) => $v === 'open' ? $q->whereNotIn('status', ['closed', 'cancelled', 'terminated']) : $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$v}%")->orWhere('ref', 'ilike', "%{$v}%")))->latest('id')->limit(200)->get();
        return response()->json(['data' => $rows->map(fn ($a) => self::assignment($a, $r->user())), 'lookups' => self::lookups(), 'can' => self::can($r->user())]);
    }

    public function assignment_show(Request $r, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        $sug = in_array($a->status, ['request', 'shortlist'], true) ? Roster::suggest($a) : [];
        $people = External::whereIn('id', array_column($sug, 'id'))->get()->keyBy('id');
        return response()->json(['data' => self::assignment($a, $r->user()) + ['suggested' => array_map(fn ($s) => $s + self::row($people[$s['id']], Externals::canFees($r->user())), $sug)]]);
    }

    private function torInput(Request $r): array
    {
        return $r->validate(['title' => ['required', 'string', 'max:200'], 'tor' => ['required', 'array'], 'tor.objective' => ['required', 'string', 'min:10', 'max:3000'], 'tor.outputs' => ['nullable', 'string', 'max:3000'],
            'tor.place' => ['nullable', 'string', 'max:200'], 'tor.specialties' => ['nullable', 'array'], 'department_id' => ['nullable', 'integer'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'budget' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3']], [], ['tor.objective' => L::t('الهدف', 'Objective')]);
    }

    public function storeAssignment(Request $r): JsonResponse
    {
        $a = $this->roster->request($this->torInput($r), $r->user());
        return response()->json(['id' => $a->id], 201);
    }

    public function updateAssignment(Request $r, int $id): JsonResponse
    {
        $this->roster->request($this->torInput($r), $r->user(), Assignment::findOrFail($id));
        return $this->assignment_show($r, $id);
    }

    public function shortlist(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['items' => ['present', 'array'], 'items.*.external_id' => ['required', 'integer'], 'items.*.note' => ['nullable', 'string', 'max:255']]);
        $this->roster->shortlist(Assignment::findOrFail($id), $v['items']);
        return $this->assignment_show($r, $id);
    }

    public function select(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['external_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['nullable', 'boolean']]);
        $this->roster->select(Assignment::findOrFail($id), $v['external_id'], $v['reason'], (bool) ($v['confirm'] ?? false), $r->user());
        return $this->assignment_show($r, $id);
    }

    public function contract(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['total_fee' => ['required', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'tax_treatment' => ['nullable', 'string', 'max:255'], 'file_id' => ['nullable', 'integer'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'], 'relation_test' => ['nullable', 'array'],
            'deliverables' => ['required', 'array', 'min:1', 'max:20'], 'deliverables.*.title' => ['required', 'string', 'max:200'], 'deliverables.*.due_on' => ['nullable', 'date'], 'deliverables.*.payment' => ['required', 'numeric', 'min:0']]);
        if (! Externals::canFees($r->user())) abort(403);
        $this->roster->contract(Assignment::findOrFail($id), $v, $r->user());
        return $this->assignment_show($r, $id);
    }

    public function pledge(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['kinds' => ['required', 'array'], 'conflict' => ['nullable', 'string', 'max:1000']]);
        $this->roster->pledge(Assignment::findOrFail($id), $r->user(), $v['kinds'], $v['conflict'] ?? null);
        return $this->assignment_show($r, $id);
    }

    public function deliver(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['file_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:1000']]);
        $d = $this->roster->submit(Deliverable::findOrFail($id), $r->user(), $v['file_id'], $v['note'] ?? null);
        return $this->assignment_show($r, $d->assignment_id);
    }

    public function reviewDeliverable(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000'], 'due_on' => ['nullable', 'date']]);
        $d = $this->roster->review(Deliverable::findOrFail($id), $v['approve'], $v['note'] ?? null, $v['due_on'] ?? null, $r->user());
        return $this->assignment_show($r, $d->assignment_id);
    }

    public function rate(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['quality' => ['required', 'integer'], 'timeliness' => ['required', 'integer'], 'communication' => ['required', 'integer'], 'value' => ['required', 'integer'],
            'rehire' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'], 'early' => ['nullable', 'boolean']]);
        $this->roster->rate(Assignment::findOrFail($id), $v, $r->user());
        return $this->assignment_show($r, $id);
    }

    public function cancelAssignment(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->roster->cancel(Assignment::findOrFail($id), $v['reason'], $r->user());
        return $this->assignment_show($r, $id);
    }

    // ——— المدفوعات ———

    public function payments(Request $r): JsonResponse
    {
        $u = $r->user();
        if (! Externals::canFees($u) && ! Payments::canDecide($u)) abort(403);
        $f = $r->validate(['status' => ['nullable', 'in:pending,approved,paid,rejected,open']]);
        $rows = PaymentRequest::with('external')->when($f['status'] ?? 'open', fn ($q, $v) => $v === 'open' ? $q->whereIn('status', ['pending', 'approved']) : $q->where('status', $v))->latest('id')->limit(300)->get();
        return response()->json(['data' => $rows->map(fn ($p) => self::payment($p)), 'can' => self::can($u)]);
    }

    public function decidePayment(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['action' => ['required', 'in:approve,pay,reject'], 'finance_ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255']]);
        return response()->json(['data' => self::payment($this->pay->decide(PaymentRequest::findOrFail($id), $v['action'], $v['finance_ref'] ?? null, $v['note'] ?? null, $r->user()))]);
    }

    // ——— فرص التطوع ———

    public function opportunities(Request $r): JsonResponse
    {
        $origin = $r->headers->get('origin') ?: rtrim((string) config('app.frontend_url'), '/');
        $count = External::whereNotNull('opportunity_id')->selectRaw('opportunity_id, count(*) n')->groupBy('opportunity_id')->pluck('n', 'opportunity_id');
        return response()->json(['data' => VolunteerOpportunity::latest('id')->get()->map(fn ($o) => ['id' => $o->id, 'title' => $o->title, 'title_en' => $o->title_en, 'description' => $o->description,
            'department_id' => $o->department_id, 'location_id' => $o->location_id, 'starts_on' => $o->starts_on?->toDateString(), 'ends_on' => $o->ends_on?->toDateString(), 'slots' => $o->slots, 'weekly_hours' => $o->weekly_hours,
            'status' => $o->status, 'url' => rtrim($origin, '/').'/volunteer/'.$o->token, 'applicants' => (int) ($count[$o->id] ?? 0),
            'kind' => $o->kind, 'stage_type' => $o->stage_type, 'fee' => $o->fee !== null ? (float) $o->fee : null, 'fee_currency' => $o->fee_currency])]);
    }

    public function saveOpportunity(Request $r, ?int $id = null): JsonResponse
    {
        $v = $r->validate(['title' => ['required', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:3000'], 'department_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'], 'slots' => ['nullable', 'integer', 'min:0'], 'weekly_hours' => ['nullable', 'integer', 'min:0'], 'status' => ['required', 'in:draft,open,closed'],
            'kind' => ['nullable', 'in:volunteer,intern'], 'stage_type' => ['nullable', 'required_if:kind,intern', 'in:paid,free,fee'], 'fee' => ['nullable', 'required_if:stage_type,fee', 'numeric', 'min:0'], 'fee_currency' => ['nullable', 'string', 'size:3']]);
        if (($v['kind'] ?? 'volunteer') !== 'intern') $v['stage_type'] = $v['fee'] = null;
        $o = $id ? VolunteerOpportunity::findOrFail($id) : new VolunteerOpportunity(['token' => Str::random(32), 'created_by' => $r->user()->id]);
        $o->fill($v)->save();
        return $this->opportunities($r);
    }

    /** رابط التسجيل العام للخبراء */
    public function expertLink(Request $r): JsonResponse
    {
        $origin = $r->headers->get('origin') ?: rtrim((string) config('app.frontend_url'), '/');
        return response()->json(['url' => rtrim($origin, '/').'/experts/join/'.\App\Support\Tenant::company()->slug]);
    }

    // ——— التقارير والإعدادات ———

    public function reports(Request $r): JsonResponse
    {
        $v = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $v['from'] ?? now()->startOfYear()->toDateString(); $to = $v['to'] ?? now()->toDateString();
        $c = Externals::cfg(); $fees = Externals::canFees($r->user());
        $vol = External::where('kind', 'volunteer');
        $hours = (float) VolunteerLog::where('status', 'approved')->whereBetween('date', [$from, $to])->sum('hours');
        $eng = Engagement::query();
        $by = fn (string $col) => Engagement::whereIn('status', ['active', 'ended'])->selectRaw("{$col} k, count(*) n")->groupBy($col)->pluck('n', 'k');
        $ended = Engagement::whereIn('status', ['ended', 'terminated'])->count();
        $full = Engagement::where('status', 'ended')->count();
        return response()->json(['data' => [
            'volunteers' => ['total' => (clone $vol)->count(), 'active' => (clone $vol)->where('status', 'active')->count(), 'by_status' => (clone $vol)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
                'hours' => $hours, 'value' => $c['hour_value'] ? round($hours * (float) $c['hour_value']) : null, 'value_currency' => $c['hour_currency'],
                'by_branch' => $by('location_id'), 'by_department' => $by('department_id'), 'by_relation' => $by('relation'), 'by_project' => $by('project'),
                'hours_by_project' => VolunteerLog::join('engagements', 'engagements.id', '=', 'volunteer_logs.engagement_id')->where('volunteer_logs.status', 'approved')->whereBetween('volunteer_logs.date', [$from, $to])
                    ->selectRaw("coalesce(engagements.project, '—') k, sum(volunteer_logs.hours) h")->groupBy('k')->pluck('h', 'k'), 'retention' => $ended ? round($full * 100 / $ended) : null,
                'high' => Engagement::where(fn ($q) => $q->where('relation', 'replaces')->orWhere('relation_score', '>=', (int) $c['relation_threshold']))->whereIn('status', ['active', 'approved'])->count()],
            'experts' => ['total' => External::whereIn('kind', ['expert', 'consultant'])->count(), 'approved' => External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->count(),
                'by_specialty' => collect(External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->pluck('specialties'))->flatten()->countBy()->sortDesc()->take(12),
                'by_country' => External::whereIn('kind', ['expert', 'consultant'])->selectRaw('country, count(*) n')->groupBy('country')->pluck('n', 'country'),
                'live' => Assignment::whereIn('status', Roster::LIVE)->count(),
                'top' => External::whereIn('kind', ['expert', 'consultant'])->whereNotNull('avg_rating')->orderByDesc('avg_rating')->limit(10)->get()->map(fn ($x) => ['id' => $x->id, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name, 'rating' => $x->avg_rating, 'n' => $x->ratings]),
                'paid' => $fees ? PaymentRequest::where('status', 'paid')->whereBetween('paid_at', [$from, Carbon::parse($to)->endOfDay()])->selectRaw('currency, source_type, sum(amount) s')->groupBy('currency', 'source_type')->get() : null,
                'paid_by_department' => $fees ? Assignment::join('deliverables', 'deliverables.assignment_id', '=', 'assignments.id')->join('payment_requests', fn ($j) => $j->on('payment_requests.source_id', '=', 'deliverables.id')->where('payment_requests.source_type', 'deliverable'))
                    ->where('payment_requests.status', 'paid')->selectRaw('assignments.department_id d, payment_requests.currency c, sum(payment_requests.amount) s')->groupBy('assignments.department_id', 'payment_requests.currency')->get() : null],
        ], 'lookups' => self::lookups()]);
    }

    /** بطاقة NFC لغير موظف (FR-VL-007): إصدار، وعرض البطاقات الحالية */
    public function card(Request $r, int $id): JsonResponse
    {
        abort_unless(Perm::can($r->user(), 'externals', 'edit'), 403);
        $x = External::findOrFail($id);
        if ($r->isMethod('post')) \App\Services\Attendance\NfcGate::issueExternal($x, (string) $r->validate(['identifier' => ['required', 'string', 'max:60']])['identifier'], $r->user());
        return response()->json(['data' => \App\Models\Credential::where('external_id', $x->id)->latest('id')->get()->map(fn ($c) => ['id' => $c->id, 'identifier' => $c->identifier, 'status' => $c->status, 'valid_from' => $c->valid_from?->toDateString(), 'revoked_at' => $c->revoked_at?->toIso8601String()])]);
    }

    public function settings(Request $r): JsonResponse
    {
        return response()->json(['data' => Externals::cfg(), 'defaults' => Settings::DEFAULTS['externals'], 'can' => ['edit' => Perm::can($r->user(), 'settings', 'edit')]]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $v = $r->validate(['max_months' => ['required', 'integer', 'between:1,36'], 'max_weekly_hours' => ['required', 'integer', 'between:1,60'], 'warn_pct' => ['required', 'integer', 'between:50,100'],
            'relation_threshold' => ['required', 'integer', 'between:1,6'], 'replaces_max_months' => ['required', 'integer', 'between:1,24'], 'default_relation' => ['required', 'in:replaces,alongside,independent'],
            'approver' => ['required', 'in:hr_director,hr_officer,ceo,executive,admin'], 'high_approver' => ['required', 'in:hr_director,ceo,executive,admin'],
            'pledges' => ['present', 'array'], 'pledges.*' => ['in:agreement,confidentiality,conduct,safety'], 'onboarding' => ['present', 'array', 'max:15'], 'onboarding.*.ar' => ['required', 'string', 'max:200'], 'onboarding.*.en' => ['nullable', 'string', 'max:200'],
            'expense_cap' => ['required', 'numeric', 'min:0'], 'expense_currency' => ['required', 'string', 'size:3'], 'hour_value' => ['required', 'numeric', 'min:0'], 'hour_currency' => ['required', 'string', 'size:3'],
            'end_warn_days' => ['required', 'integer', 'between:1,90'], 'roster_review_months' => ['required', 'integer', 'between:1,36'], 'roster_inactive_days' => ['required', 'integer', 'between:7,180'],
            'shortlist_min' => ['required', 'integer', 'between:1,10'], 'doc_alert_days' => ['required', 'integer', 'between:1,180'], 'fee_roles' => ['present', 'array'], 'fee_roles.*' => ['in:hr_director,hr_officer,finance,ceo,executive,manager'],
            'agreement_ar' => ['required', 'string', 'max:20000'], 'agreement_en' => ['required', 'string', 'max:20000']]);
        $v['onboarding'] = array_map(fn ($i) => ['ar' => $i['ar'], 'en' => $i['en'] ?? $i['ar']], $v['onboarding']);
        $old = Externals::cfg();
        Settings::put('externals', $v);
        // سلسلة الاعتماد تتبع الإعداد (للطلبات الجديدة)
        \App\Models\ApprovalChain::updateOrCreate(['type' => 'engagement'], ['levels' => [Workflow::level($v['approver'])]]);
        \App\Models\ApprovalChain::updateOrCreate(['type' => 'engagement_high'], ['levels' => [Workflow::level($v['approver']), Workflow::level($v['high_approver'])]]);
        Audit::record('settings.externals', null, ['before' => array_diff_key($old, ['agreement_ar' => 1, 'agreement_en' => 1]), 'after' => array_diff_key($v, ['agreement_ar' => 1, 'agreement_en' => 1])]);
        return $this->settings($r);
    }
}
