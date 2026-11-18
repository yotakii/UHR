<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Competency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompetency;
use App\Models\JobCompetency;
use App\Models\Location;
use App\Models\StoredFile;
use App\Models\TdCampaign;
use App\Models\TdCbp;
use App\Models\TdCenter;
use App\Models\TdCertificate;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdPlan;
use App\Models\TdPlanItem;
use App\Models\TdProgram;
use App\Models\TdQualification;
use App\Models\TdRating;
use App\Models\TdSession;
use App\Models\TdSurvey;
use App\Models\TdTrainer;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Services\Training\Needs;
use App\Services\Training\Plans;
use App\Services\Training\Records;
use App\Services\Training\Sessions;
use App\Services\Training\Td;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** موديول التأهيل والتدريب والتطوير (الدفعة 23): شاشات TD-00…13، وبوابة الموظف والمدير، والروابط العامة للاستبيان والتحقق */
class TrainingController extends Controller
{
    public function __construct(private Needs $needs, private Plans $plans, private Sessions $sessions) {}

    private function hr(Request $r): void { if (! Td::manages($r->user())) abort(403); }
    private static function bi($m, string $a = 'name_ar', string $e = 'name_en'): ?array { return $m ? ['id' => $m->id, 'ar' => $m->$a, 'en' => $m->$e ?: $m->$a] : null; }

    // ———————————————— اللوحة والمكتبات ————————————————

    public function index(Request $r): JsonResponse
    {
        $u = $r->user(); $year = (int) $r->query('year', now()->year);
        $plan = TdPlan::where('year', $year)->where('scope', 'company')->first();
        $week = TdSession::whereBetween('starts_on', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->whereNotIn('status', ['cancelled'])->get();
        return response()->json([
            'year' => $year, 'plan' => $plan ? ['id' => $plan->id, 'status' => $plan->status, 'progress' => Plans::progress($plan), 'currency' => $plan->currency] : null,
            'week' => $week->map(fn ($s) => self::sessionRow($s)),
            'pending' => ['needs' => TdNeed::whereIn('status', ['suggested', 'submitted'])->count(), 'commitments' => TdEnrollment::where('status', 'pending_commitment')->count(), 'nominations' => TdEnrollment::where('status', 'nominated')->count(),
                'impacts' => TdImpact::where('status', 'pending')->where('due_on', '<=', now()->toDateString())->count(), 'certificates' => TdCertificate::where('status', 'pending')->count(),
                'breached' => TdCommitment::where('status', 'breached')->count(), 'closable' => TdSession::where('status', 'completed')->count(), 'qualifications' => TdQualification::where('status', '!=', 'done')->count()],
            'alerts' => array_values(array_filter([
                TdCertificate::where('status', 'valid')->whereNotNull('valid_to')->where('valid_to', '<=', now()->addDays(30)->toDateString())->count() ? ['key' => 'certs', 'n' => TdCertificate::where('status', 'valid')->whereNotNull('valid_to')->where('valid_to', '<=', now()->addDays(30)->toDateString())->count()] : null,
                TdProgram::where('active', true)->where(fn ($q) => $q->whereNull('reviewed_on')->orWhere('reviewed_on', '<', now()->subYear()->toDateString()))->count() ? ['key' => 'review', 'n' => TdProgram::where('active', true)->where(fn ($q) => $q->whereNull('reviewed_on')->orWhere('reviewed_on', '<', now()->subYear()->toDateString()))->count()] : null,
            ])),
            'lookups' => self::lookups($u), 'can' => self::can($u)]);
    }

    public static function can(User $u): array
    {
        return ['manage' => Td::manages($u), 'create' => Perm::can($u, 'training', 'create'), 'decide' => Perm::can($u, 'training', 'decide'), 'export' => Perm::can($u, 'training', 'export'),
            'settings' => Perm::can($u, 'settings', 'edit'), 'director' => $u->hasRole('hr_director', 'admin')];
    }

    public static function lookups(User $u): array
    {
        $c = Td::cfg();
        return ['programs' => TdProgram::where('active', true)->orderBy('code')->get(['id', 'code', 'title', 'title_en', 'path', 'delivery', 'hours', 'method', 'mandatory', 'competencies'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'ar' => $p->title, 'en' => $p->title_en ?: $p->title, 'path' => $p->path, 'delivery' => $p->delivery, 'hours' => $p->hours, 'method' => $p->method, 'mandatory' => $p->mandatory, 'competencies' => $p->competencies ?? []]),
            'trainers' => TdTrainer::orderBy('name')->get(['id', 'name', 'name_en', 'kind', 'status', 'rating'])->map(fn ($t) => ['id' => $t->id, 'ar' => $t->name, 'en' => $t->name_en ?: $t->name, 'kind' => $t->kind, 'status' => $t->status, 'rating' => $t->rating]),
            'centers' => TdCenter::orderBy('name')->get(['id', 'name', 'name_en', 'status', 'quality'])->map(fn ($t) => ['id' => $t->id, 'ar' => $t->name, 'en' => $t->name_en ?: $t->name, 'status' => $t->status, 'quality' => $t->quality]),
            'competencies' => Competency::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en', 'kind'])->map(fn ($x) => ['id' => $x->id, 'ar' => $x->name_ar, 'en' => $x->name_en, 'kind' => $x->kind]),
            'departments' => Department::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en', 'head_id'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en, 'head_id' => $d->head_id]),
            'branches' => Location::where('active', true)->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'employees' => Td::scoped($u)->whereNotIn('status', ['terminated'])->orderBy('name')->get(['id', 'code', 'name', 'name_en', 'department_id', 'status'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'department_id' => $e->department_id, 'status' => $e->status]),
            'jobs' => Codes::items('jobs'), 'currencies' => Codes::items('currencies'),
            'rooms' => \App\Models\TdRoom::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'capacity', 'location_id'])->map(fn ($x) => ['id' => $x->id, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name, 'capacity' => $x->capacity, 'location_id' => $x->location_id]),
            'cfg' => array_intersect_key($c, array_flip(['mode', 'pass_mark', 'attendance_min', 'min_evidence', 'survey_items', 'impact_items', 'cost_types', 'impact_points', 'capacity', 'min_quotes', 'deliveries', 'audiences', 'quote_criteria', 'smart_required', 'tna_enforce', 'tna_from_month', 'tna_to_month']))];
    }

    // ———————————————— الكفاءات والجهات والبرامج ————————————————

    public function competencies(Request $r): JsonResponse
    {
        return response()->json(['data' => Competency::orderBy('sort')->get()->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en, 'kind' => $c->kind, 'levels' => $c->levels, 'active' => $c->active]),
            'jobs' => JobCompetency::get(['job_id', 'competency_id', 'level'])->groupBy('job_id')->map(fn ($g) => $g->pluck('level', 'competency_id')), 'job_list' => Codes::items('jobs')]);
    }

    public function saveCompetency(Request $r, int $id): JsonResponse
    {
        $this->hr($r);
        $v = $r->validate(['kind' => ['required', 'in:technical,behavioral,leadership'], 'levels' => ['nullable', 'array', 'max:5'], 'levels.*' => ['nullable', 'string', 'max:300']]);
        Competency::findOrFail($id)->update($v);
        return $this->competencies($r);
    }

    public function jobCompetencies(Request $r): JsonResponse
    {
        $this->hr($r);
        $v = $r->validate(['job_id' => ['required', 'integer'], 'items' => ['present', 'array'], 'items.*.competency_id' => ['required', 'integer'], 'items.*.level' => ['required', 'integer', 'between:1,5']]);
        if (! Codes::resolve('jobs', $v['job_id'])) abort(404);
        JobCompetency::where('job_id', $v['job_id'])->delete();
        foreach ($v['items'] as $i) JobCompetency::create(['job_id' => $v['job_id'], 'competency_id' => $i['competency_id'], 'level' => $i['level']]);
        Audit::record('td.job_competencies', null, ['job' => $v['job_id'], 'items' => $v['items']]);
        return $this->competencies($r);
    }

    public function setEmployeeLevel(Request $r): JsonResponse
    {
        $v = $r->validate(['employee_id' => ['required', 'integer'], 'competency_id' => ['required', 'integer'], 'level' => ['required', 'integer', 'between:1,5']]);
        if (! Td::manages($r->user())) abort(403);
        Td::setLevel(Employee::findOrFail($v['employee_id']), $v['competency_id'], $v['level'], 'manual', 'user:'.$r->user()->id);
        return response()->json(['ok' => true]);
    }

    public function trainers(Request $r): JsonResponse
    {
        $fees = $r->user()->hasRole('hr_director', 'finance', 'admin', 'ceo');
        return response()->json(['data' => TdTrainer::with(['employee:id,code,name,name_en', 'center:id,name'])->orderBy('name')->get()->map(fn ($t) => ['id' => $t->id, 'kind' => $t->kind, 'ar' => $t->name, 'en' => $t->name_en ?: $t->name,
            'employee' => $t->employee ? ['id' => $t->employee->id, 'code' => $t->employee->code] : null, 'external_id' => $t->external_id, 'center' => $t->center?->name, 'center_id' => $t->center_id, 'qualifications' => $t->qualifications, 'years' => $t->years,
            'specialties' => $t->specialties ?? [], 'cv_file_id' => $t->cv_file_id, 'hourly_rate' => $fees ? $t->hourly_rate : null, 'currency' => $t->currency, 'rating' => $t->rating, 'ratings' => $t->ratings, 'hours_given' => $t->hours_given,
            'status' => $t->status, 'status_note' => $t->status_note]),
            'centers' => TdCenter::orderBy('name')->get()->map(fn ($c) => $c->only(['id', 'name', 'name_en', 'contact', 'phone', 'email', 'city', 'accreditations', 'quality', 'ratings', 'status', 'status_note'])),
            'experts' => \App\Models\External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->orderBy('name')->get(['id', 'name', 'name_en'])->map(fn ($x) => ['id' => $x->id, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name])]);
    }

    public function saveTrainer(Request $r, ?int $id = null): JsonResponse
    {
        $this->hr($r);
        $v = $r->validate(['kind' => ['required', 'in:internal,external'], 'employee_id' => ['nullable', 'integer'], 'external_id' => ['nullable', 'integer'], 'center_id' => ['nullable', 'integer'], 'name' => ['required_without_all:employee_id,external_id', 'nullable', 'string', 'max:160'],
            'name_en' => ['nullable', 'string', 'max:160'], 'qualifications' => ['nullable', 'string', 'max:3000'], 'years' => ['nullable', 'integer', 'between:0,60'], 'specialties' => ['nullable', 'array'], 'cv_file_id' => ['nullable', 'integer'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3']]);
        if (! empty($v['employee_id']) && ($e = Employee::find($v['employee_id']))) { $v['name'] = $e->name; $v['name_en'] = $e->name_en; }
        if (! empty($v['external_id']) && ($x = \App\Models\External::find($v['external_id']))) { $v['name'] = $x->name; $v['name_en'] = $x->name_en; }
        $id ? TdTrainer::findOrFail($id)->update($v) : TdTrainer::create($v + ['status' => 'approved']);
        return $this->trainers($r);
    }

    public function saveCenter(Request $r, ?int $id = null): JsonResponse
    {
        $this->hr($r);
        $v = $r->validate(['name' => ['required', 'string', 'max:160'], 'name_en' => ['nullable', 'string', 'max:160'], 'contact' => ['nullable', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'], 'city' => ['nullable', 'string', 'max:80'], 'accreditations' => ['nullable', 'string', 'max:2000']]);
        $id ? TdCenter::findOrFail($id)->update($v) : TdCenter::create($v + ['status' => 'approved']);
        return $this->trainers($r);
    }

    /** POL-14: الحظر بقرار مدير الموارد البشرية */
    public function providerStatus(Request $r, string $kind, int $id): JsonResponse
    {
        if (! $r->user()->hasRole('hr_director', 'admin')) abort(403);
        $v = $r->validate(['status' => ['required', 'in:approved,watch,banned'], 'note' => ['nullable', 'string', 'max:255']]);
        if ($v['status'] === 'banned' && mb_strlen(trim((string) ($v['note'] ?? ''))) < 5) throw ApiException::field('note', L::t('اكتب سبب الحظر.', 'Write the reason.'), 'reason_required');
        ($kind === 'center' ? TdCenter::findOrFail($id) : TdTrainer::findOrFail($id))->update(['status' => $v['status'], 'status_note' => $v['note'] ?? null]);
        Audit::record('td.provider_status', null, ['kind' => $kind, 'id' => $id] + $v);
        return $this->trainers($r);
    }

    public function programs(Request $r): JsonResponse
    {
        $ran = TdSession::selectRaw('program_id, count(*) n')->groupBy('program_id')->pluck('n', 'program_id');
        return response()->json(['data' => TdProgram::orderBy('code')->get()->map(fn ($p) => $p->toArray() + ['runs' => (int) ($ran[$p->id] ?? 0), 'review_due' => ! $p->reviewed_on || $p->reviewed_on->lt(now()->subYear())])]);
    }

    public function saveProgram(Request $r, ?int $id = null): JsonResponse
    {
        $this->hr($r);
        $v = $r->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'], 'title' => ['required', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'], 'path' => ['required', 'in:'.implode(',', Td::PATHS)],
            'delivery' => ['required', 'in:'.implode(',', \App\Services\Training\TdExtras::deliveryKeys())], 'audience' => ['required', 'in:'.implode(',', \App\Services\Training\TdExtras::audienceKeys())], 'method' => ['required', 'in:general,workshop,lecture,group'], 'objectives' => ['nullable', 'string', 'max:3000'],
            'outcomes' => ['nullable', 'string', 'max:3000'], 'modules' => ['nullable', 'array', 'max:30'], 'hours' => ['required', 'numeric', 'min:0', 'max:2000'], 'prerequisites' => ['nullable', 'array'], 'competencies' => ['nullable', 'array'],
            'competencies.*.id' => ['required', 'integer'], 'competencies.*.level' => ['required', 'integer', 'between:1,5'], 'mandatory' => ['boolean'], 'materials' => ['nullable', 'array'], 'reviewed_on' => ['nullable', 'date'],
            'pass_mark' => ['nullable', 'integer', 'between:1,100'], 'attendance_min' => ['nullable', 'integer', 'between:1,100'], 'has_test' => ['boolean'], 'kpi_id' => ['nullable', 'integer'], 'impact_items' => ['nullable', 'array', 'max:15'], 'active' => ['boolean']]);
        if (TdProgram::where('code', $v['code'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) throw ApiException::field('code', L::t('الرمز مستخدم.', 'Code in use.'), 'duplicate');
        $id ? TdProgram::findOrFail($id)->update($v) : TdProgram::create($v);
        return $this->programs($r);
    }

    // ———————————————— الاحتياجات والحملات ————————————————

    private function needInput(Request $r): array
    {
        return $r->validate(['employee_id' => ['nullable', 'integer'], 'employees' => ['nullable', 'array'], 'department_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'competency_id' => ['nullable', 'integer'],
            'current_level' => ['nullable', 'integer', 'between:1,5'], 'required_level' => ['nullable', 'integer', 'between:1,5'], 'title' => ['required', 'string', 'max:200'], 'path' => ['required', 'in:'.implode(',', Td::PATHS)],
            'level' => ['nullable', 'in:company,job,individual'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'factors' => ['nullable', 'array'], 'cause' => ['nullable', 'in:'.implode(',', Td::CAUSES)],
            'evidence' => ['nullable', 'array'], 'evidence.*' => ['in:'.implode(',', Td::EVIDENCE)], 'evidence_override' => ['nullable', 'string', 'max:2000'], 'objective' => ['nullable', 'array'], 'note' => ['nullable', 'string', 'max:2000'],
            'program_id' => ['nullable', 'integer'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'preferred_date' => ['nullable', 'string', 'max:40'], 'source' => ['nullable', 'in:request,gap,kpi,event,campaign,idp']]);
    }

    public static function needRow(TdNeed $n): array
    {
        $n->loadMissing(['employee:id,code,name,name_en', 'competency:id,name_ar,name_en', 'department:id,name_ar,name_en', 'program:id,code,title']);
        return ['id' => $n->id, 'ref' => $n->ref, 'title' => $n->title, 'employee' => $n->employee ? ['id' => $n->employee->id, 'code' => $n->employee->code, 'ar' => $n->employee->name, 'en' => $n->employee->name_en] : null,
            'employees' => $n->employees ?? [], 'department' => self::bi($n->department), 'competency' => self::bi($n->competency), 'current_level' => $n->current_level, 'required_level' => $n->required_level,
            'path' => $n->path, 'level' => $n->level, 'priority' => $n->priority, 'score' => $n->score, 'factors' => $n->factors, 'source' => $n->source, 'cause' => $n->cause, 'evidence' => $n->evidence ?? [], 'evidence_override' => $n->evidence_override,
            'objective' => $n->objective, 'status' => $n->status, 'note' => $n->note, 'program' => $n->program ? ['id' => $n->program->id, 'code' => $n->program->code, 'title' => $n->program->title] : null,
            'estimated_cost' => $n->estimated_cost, 'preferred_date' => $n->preferred_date, 'campaign_id' => $n->campaign_id, 'plan_item_id' => $n->plan_item_id, 'competency_id' => $n->competency_id, 'department_id' => $n->department_id, 'program_id' => $n->program_id, 'created_at' => $n->created_at?->toIso8601String()];
    }

    public function needs(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => ['nullable', 'string'], 'source' => ['nullable', 'string'], 'department' => ['nullable', 'integer'], 'path' => ['nullable', 'string'], 'q' => ['nullable', 'string']]);
        $u = $r->user(); $scope = Td::manages($u) ? null : Td::scoped($u)->pluck('id');
        $rows = TdNeed::when($scope, fn ($q) => $q->where(fn ($w) => $w->whereIn('employee_id', $scope)->orWhere('created_by', $u->id)))
            ->when(($f['status'] ?? null) === 'open', fn ($q) => $q->whereIn('status', ['suggested', 'submitted']), fn ($q) => $q->when($f['status'] ?? null, fn ($w, $v) => $w->where('status', $v)))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))->when($f['path'] ?? null, fn ($q, $v) => $q->where('path', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$v}%")->orWhere('ref', 'ilike', "%{$v}%")))->orderByDesc('score')->latest('id')->limit(300)->get();
        return response()->json(['data' => $rows->map(fn ($n) => self::needRow($n)), 'groups' => Td::manages($u) ? Needs::groups() : []]);
    }

    public function storeNeed(Request $r): JsonResponse { return response()->json(['data' => self::needRow($this->needs->save($this->needInput($r), $r->user()))], 201); }
    public function updateNeed(Request $r, int $id): JsonResponse { return response()->json(['data' => self::needRow($this->needs->save($this->needInput($r), $r->user(), TdNeed::findOrFail($id)))]); }

    public function decideNeed(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['action' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:2000']]);
        return response()->json(['data' => self::needRow($this->needs->decide(TdNeed::findOrFail($id), $v['action'], $v['note'] ?? null, $r->user()))]);
    }

    public function matrix(Request $r): JsonResponse
    {
        $f = $r->validate(['department' => ['nullable', 'integer'], 'branch' => ['nullable', 'integer'], 'job' => ['nullable', 'integer']]);
        return response()->json($this->needs->matrix($r->user(), $f));
    }

    public function campaigns(Request $r): JsonResponse
    {
        return response()->json(['data' => TdCampaign::latest('id')->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'year' => $c->year, 'scope' => $c->scope, 'opens_on' => $c->opens_on->toDateString(), 'closes_on' => $c->closes_on->toDateString(),
            'status' => $c->status, 'departments' => $this->needs->campaignDepartments($c)->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en, 'submitted' => $c->responses[$d->id] ?? null])->values(),
            'needs' => TdNeed::where('campaign_id', $c->id)->count(), 'rate' => \App\Services\Training\TdExtras::campaignRate($c)])]);
    }

    public function storeCampaign(Request $r): JsonResponse
    {
        $v = $r->validate(['title' => ['required', 'string', 'max:200'], 'year' => ['required', 'integer', 'between:2020,2100'], 'scope' => ['nullable', 'in:company,branch,department'], 'scope_ids' => ['nullable', 'array'],
            'opens_on' => ['nullable', 'date'], 'closes_on' => ['required', 'date', 'after_or_equal:today'], 'window_reason' => ['nullable', 'string', 'max:1000']]);
        $this->needs->campaign($v, $r->user());
        return $this->campaigns($r);
    }

    public function closeCampaign(Request $r, int $id): JsonResponse { $this->needs->closeCampaign(TdCampaign::findOrFail($id), $r->user()); return $this->campaigns($r); }

    public function campaignForm(Request $r, int $id, int $dept): JsonResponse
    {
        $c = TdCampaign::findOrFail($id); $d = Department::findOrFail($dept);
        if (! Td::manages($r->user()) && ($r->user()->employee_id ?? 0) !== $d->head_id) abort(403);
        return response()->json(['campaign' => ['id' => $c->id, 'title' => $c->title, 'year' => $c->year, 'status' => $c->status, 'closes_on' => $c->closes_on->toDateString()], 'department' => self::bi($d),
            'prefill' => $this->needs->prefill($d), 'needs' => TdNeed::where('campaign_id', $c->id)->where('department_id', $d->id)->get()->map(fn ($n) => self::needRow($n))]);
    }

    public function submitCampaignForm(Request $r, int $id, int $dept): JsonResponse
    {
        $v = $r->validate(['items' => ['required', 'array', 'min:1', 'max:60'], 'items.*.title' => ['required', 'string', 'max:200'], 'items.*.path' => ['required', 'in:'.implode(',', Td::PATHS)], 'items.*.employees' => ['nullable', 'array'],
            'items.*.employee_id' => ['nullable', 'integer'], 'items.*.competency_id' => ['nullable', 'integer'], 'items.*.note' => ['nullable', 'string'], 'items.*.preferred_date' => ['nullable', 'string', 'max:40'], 'items.*.priority' => ['nullable', 'in:low,normal,high,urgent']]);
        $n = $this->needs->submitCampaign(TdCampaign::findOrFail($id), Department::findOrFail($dept), $v['items'], $r->user());
        return response()->json(['count' => $n]);
    }

    // ———————————————— الخطة ————————————————

    public function plans(Request $r): JsonResponse
    {
        return response()->json(['data' => TdPlan::orderByDesc('year')->get()->map(fn ($p) => ['id' => $p->id, 'year' => $p->year, 'title' => $p->title, 'scope' => $p->scope, 'status' => $p->status, 'budget' => $p->budget, 'currency' => $p->currency,
            'progress' => Plans::progress($p)]), 'groups' => Td::manages($r->user()) ? Needs::groups() : []]);
    }

    public function plan(Request $r, int $id): JsonResponse
    {
        $p = TdPlan::findOrFail($id); $cur = Workflow::current($p);
        $items = $p->items()->with('program:id,code,title')->orderBy('month')->orderBy('id')->get();
        return response()->json(['data' => ['id' => $p->id, 'year' => $p->year, 'title' => $p->title, 'scope' => $p->scope, 'location_id' => $p->location_id, 'status' => $p->status, 'budget' => $p->budget, 'currency' => $p->currency,
            'budget_split' => $p->budget_split, 'baseline' => $p->baseline, 'finance_note' => $p->finance_note, 'progress' => Plans::progress($p), 'checks' => Plans::checks($p),
            'items' => $items->map(fn ($i) => $i->toArray() + ['needs' => TdNeed::where('plan_item_id', $i->id)->count(), 'sessions' => TdSession::where('plan_item_id', $i->id)->get(['id', 'ref', 'status', 'starts_on'])]),
            'change_requests' => \App\Models\TdApproval::where('plan_id', $p->id)->where('kind', 'plan_change')->latest('id')->limit(30)->get()->map(fn ($a) => ['id' => $a->id, 'item_id' => $a->item_id, 'title' => $a->data['title'] ?? null,
                'reason' => $a->reason, 'cost_delta' => $a->cost_delta, 'status' => $a->status, 'at' => $a->created_at?->toIso8601String(), 'steps' => Workflow::steps($a)]),
            'steps' => Workflow::steps($p), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($r->user(), $cur, $p) : false]]);
    }

    public function storePlan(Request $r): JsonResponse
    {
        $v = $r->validate(['year' => ['required', 'integer', 'between:2020,2100'], 'title' => ['required', 'string', 'max:200'], 'scope' => ['nullable', 'in:company,branch'], 'location_id' => ['nullable', 'integer'],
            'budget' => ['required', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'budget_split' => ['nullable', 'array'], 'copy_from' => ['nullable', 'integer']]);
        $p = $this->plans->save($v, $r->user());
        return response()->json(['id' => $p->id], 201);
    }

    public function updatePlan(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['year' => ['required', 'integer'], 'title' => ['required', 'string', 'max:200'], 'budget' => ['required', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'budget_split' => ['nullable', 'array']]);
        $this->plans->save($v, $r->user(), TdPlan::findOrFail($id));
        return $this->plan($r, $id);
    }

    private function itemInput(Request $r): array
    {
        return $r->validate(['program_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:200'], 'path' => ['required', 'in:'.implode(',', Td::PATHS)], 'delivery' => ['required', 'in:'.implode(',', \App\Services\Training\TdExtras::deliveryKeys())],
            'audience' => ['nullable', 'in:'.implode(',', \App\Services\Training\TdExtras::audienceKeys())], 'location_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'month' => ['nullable', 'integer', 'between:1,12'],
            'trainees' => ['nullable', 'integer', 'min:0'], 'estimated_cost' => ['required', 'numeric', 'min:0'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'objective' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:planned,confirmed,in_progress,done,postponed,cancelled'], 'need_ids' => ['nullable', 'array'], 'change_reason' => ['nullable', 'string', 'max:500']]);
    }

    public function storeItem(Request $r, int $id): JsonResponse { $this->plans->item(TdPlan::findOrFail($id), $this->itemInput($r), $r->user()); return $this->plan($r, $id); }
    public function updateItem(Request $r, int $id): JsonResponse { $i = TdPlanItem::findOrFail($id); $this->plans->item(TdPlan::findOrFail($i->plan_id), $this->itemInput($r), $r->user(), $i); return $this->plan($r, $i->plan_id); }
    public function submitPlan(Request $r, int $id): JsonResponse { $this->plans->submit(TdPlan::findOrFail($id), $r->user()); return $this->plan($r, $id); }

    public function decidePlan(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->plans->decide(TdPlan::findOrFail($id), $r->user(), $v['approve'], $v['note'] ?? null);
        return $this->plan($r, $id);
    }

    public function planExport(Request $r, int $id)
    {
        if (! Perm::can($r->user(), 'training', 'export')) abort(403);
        $p = TdPlan::findOrFail($id);
        $path = tempnam(sys_get_temp_dir(), 'tdp').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer(); $w->openToFile($path);
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['#', 'البرنامج', 'المسار', 'النوع', 'الفئة', 'الشهر', 'عدد المتدربين', 'التكلفة التقديرية', 'التكلفة الفعلية', 'الأولوية', 'الهدف المرتبط', 'الحالة']));
        foreach ($p->items()->orderBy('month')->get() as $k => $i) $w->addRow(\OpenSpout\Common\Entity\Row::fromValues([$k + 1, $i->title, $i->path, $i->delivery, $i->audience, $i->month, $i->trainees, $i->estimated_cost, $i->actual_cost, $i->priority, $i->objective, $i->status]));
        $w->close();
        Audit::record('td_plan.exported', $p);
        return response()->download($path, "training-plan-{$p->year}.xlsx")->deleteFileAfterSend();
    }

    // ———————————————— التنفيذ ————————————————

    public static function sessionRow(TdSession $s): array
    {
        $s->loadMissing(['program:id,code,title,path,delivery,hours,method,has_test', 'trainer:id,name,name_en', 'center:id,name']);
        $counts = TdEnrollment::where('session_id', $s->id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        return ['id' => $s->id, 'ref' => $s->ref, 'title' => $s->title, 'program' => $s->program ? ['id' => $s->program->id, 'code' => $s->program->code, 'path' => $s->program->path, 'delivery' => $s->program->delivery, 'hours' => $s->program->hours, 'method' => $s->program->method, 'has_test' => $s->program->has_test] : null,
            'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString(), 'meetings' => $s->meetings, 'trainer' => $s->trainer ? ['id' => $s->trainer->id, 'ar' => $s->trainer->name, 'en' => $s->trainer->name_en ?: $s->trainer->name] : null,
            'center' => $s->center?->name, 'center_id' => $s->center_id, 'location_id' => $s->location_id, 'place' => $s->place, 'link' => $s->link, 'capacity' => $s->capacity, 'status' => $s->status, 'counts' => $counts, 'plan_item_id' => $s->plan_item_id, 'currency' => $s->currency, 'total_cost' => Sessions::totalCost($s),
            'room_id' => $s->room_id, 'room' => $s->room_id ? \App\Models\TdRoom::whereKey($s->room_id)->value('name') : null];
    }

    public function sessionsList(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => ['nullable', 'string'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string']]);
        $rows = TdSession::when(($f['status'] ?? null) === 'open', fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled']), fn ($q) => $q->when($f['status'] ?? null, fn ($w, $v) => $w->where('status', $v)))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('ends_on', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('starts_on', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$v}%")->orWhere('ref', 'ilike', "%{$v}%")))->orderByDesc('starts_on')->limit(200)->get();
        return response()->json(['data' => $rows->map(fn ($s) => self::sessionRow($s))]);
    }

    public function session(Request $r, int $id): JsonResponse
    {
        $s = TdSession::findOrFail($id); $u = $r->user();
        if (! Td::manages($u) && ! Sessions::isTrainer($u, $s)) abort(403);
        $p = TdProgram::find($s->program_id);
        $ens = TdEnrollment::with('employee:id,code,name,name_en,department_id,status,manager_id')->where('session_id', $s->id)->orderBy('id')->get();
        $comm = TdCommitment::whereIn('enrollment_id', $ens->pluck('id'))->get()->keyBy('enrollment_id');
        $origin = $r->headers->get('origin') ?: rtrim((string) config('app.frontend_url'), '/');
        return response()->json(['data' => self::sessionRow($s) + ['costs' => $s->costs ?? [], 'quotes' => $s->quotes ?? [], 'quote_choice' => $s->quote_choice, 'quote_reason' => $s->quote_reason, 'logistics' => $s->logistics ?? [],
            'quote_status' => $s->quote_status, 'quote_rank' => \App\Services\Training\TdExtras::quoteRank($s), 'quote_criteria' => Td::cfg()['quote_criteria'], 'postpone_reason' => $s->postpone_reason, 'ai_summary' => $s->ai_summary,
            'candidates' => Td::manages($u) ? \App\Services\Training\TdExtras::candidates($s) : [], 'evaluated_at' => $s->evaluated_at?->toIso8601String(),
            'warnings' => Sessions::warnings($s, $p), 'survey_url' => rtrim($origin, '/').'/td-survey/'.$s->survey_token, 'checkin_url' => rtrim($origin, '/').'/td-checkin/'.$s->survey_token,
            'surveys' => ['n' => TdSurvey::where('session_id', $s->id)->count(), 'avg' => TdSurvey::where('session_id', $s->id)->avg('avg')],
            'rating' => ($rt = TdRating::where('session_id', $s->id)->first()) ? $rt->only(['scores', 'avg', 'recommendation', 'note']) : null, 'kpi' => $s->kpi, 'roi' => $s->roi,
            'program_full' => $p?->only(['id', 'code', 'title', 'pass_mark', 'attendance_min', 'has_test', 'competencies', 'hours', 'delivery']),
            'enrollments' => $ens->map(fn ($en) => ['id' => $en->id, 'employee' => $en->employee ? ['id' => $en->employee->id, 'code' => $en->employee->code, 'ar' => $en->employee->name, 'en' => $en->employee->name_en, 'status' => $en->employee->status] : null,
                'status' => $en->status, 'reason' => $en->reason, 'exception' => $en->exception, 'exclude_reason' => $en->exclude_reason, 'manager_ok' => $en->manager_ok, 'attendance' => $en->attendance ?? [], 'attendance_pct' => $en->attendance_pct,
                'pre_score' => $en->pre_score, 'post_score' => $en->post_score, 'result' => $en->result, 'result_reason' => $en->result_reason, 'failure' => $en->failure, 'cost' => $en->cost, 'apply_plan' => (bool) $en->apply_plan,
                'commitment' => isset($comm[$en->id]) ? ['id' => $comm[$en->id]->id, 'status' => $comm[$en->id]->status, 'months' => $comm[$en->id]->months, 'cost' => $comm[$en->id]->cost] : null])]]);
    }

    private function sessionInput(Request $r): array
    {
        return $r->validate(['program_id' => ['required', 'integer'], 'plan_item_id' => ['nullable', 'integer'], 'title' => ['nullable', 'string', 'max:200'], 'meetings' => ['required', 'array', 'min:1', 'max:60'],
            'meetings.*.date' => ['required', 'date'], 'meetings.*.from' => ['nullable', 'date_format:H:i'], 'meetings.*.to' => ['nullable', 'date_format:H:i'], 'trainer_id' => ['nullable', 'integer'], 'center_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:200'], 'link' => ['nullable', 'string', 'max:255'], 'capacity' => ['required', 'integer', 'between:1,500'], 'costs' => ['nullable', 'array'],
            'costs.*.type' => ['required', 'string', 'max:30'], 'costs.*.amount' => ['required', 'numeric', 'min:0'], 'costs.*.currency' => ['nullable', 'string', 'size:3'], 'currency' => ['nullable', 'string', 'size:3'],
            'quotes' => ['nullable', 'array', 'max:10'], 'quote_choice' => ['nullable', 'integer', 'min:0'], 'quote_reason' => ['nullable', 'string', 'max:2000'], 'room_id' => ['nullable', 'integer'], 'force' => ['nullable', 'boolean']]);
    }

    public function storeSession(Request $r): JsonResponse
    {
        $res = $this->sessions->save($this->sessionInput($r), $r->user());
        return response()->json(['id' => $res['session']->id, 'warnings' => $res['warnings']], 201);
    }

    public function updateSession(Request $r, int $id): JsonResponse
    {
        $this->sessions->save($this->sessionInput($r), $r->user(), TdSession::findOrFail($id));
        return $this->session($r, $id);
    }

    public function nominate(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['employee_ids' => ['required', 'array', 'min:1'], 'reason' => ['nullable', 'string', 'max:500'], 'exception' => ['nullable', 'string', 'max:500']]);
        $out = $this->sessions->nominate(TdSession::findOrFail($id), $v['employee_ids'], $v['reason'] ?? null, $v['exception'] ?? null, $r->user());
        return response()->json($out);
    }

    public function enrollment(Request $r, int $id, string $action): JsonResponse
    {
        $en = TdEnrollment::findOrFail($id); $u = $r->user();
        match ($action) {
            'confirm' => $this->sessions->confirm($en, $u),
            'withdraw' => $this->sessions->withdraw($en, $r->input('reason'), $u),
            'manager' => $this->sessions->managerDecision($en, (bool) $r->boolean('ok'), $u),
            'failure' => $this->sessions->failureDecision($en, (string) $r->input('decision'), (string) $r->input('note'), $u),
            default => abort(404),
        };
        return response()->json(['ok' => true]);
    }

    public function attendance(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['meeting' => ['required', 'integer', 'min:0'], 'marks' => ['required', 'array']]);
        $this->sessions->attendance(TdSession::findOrFail($id), $v['meeting'], $v['marks'], $r->user());
        return $this->session($r, $id);
    }

    public function scores(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['rows' => ['required', 'array'], 'rows.*.id' => ['required', 'integer'], 'rows.*.pre' => ['nullable', 'numeric', 'between:0,100'], 'rows.*.post' => ['nullable', 'numeric', 'between:0,100']]);
        $this->sessions->scores(TdSession::findOrFail($id), $v['rows'], $r->user());
        return $this->session($r, $id);
    }

    public function sessionAction(Request $r, int $id, string $action): JsonResponse
    {
        $s = TdSession::findOrFail($id); $u = $r->user();
        match ($action) {
            'complete' => $this->sessions->complete($s, $u),
            'close' => $this->sessions->close($s, $r->validate(['costs' => ['nullable', 'array']])['costs'] ?? [], $u),
            'cancel' => $this->sessions->cancel($s, (string) $r->input('reason'), $u),
            'rate' => $this->sessions->rate($s, (array) $r->input('scores', []), $r->input('note'), $u),
            'logistics' => $this->hr($r) ?? $s->update(['logistics' => $r->validate(['logistics' => ['required', 'array']])['logistics']]),
            'kpi' => $this->hr($r) ?? $s->update(['kpi' => $r->validate(['kpi' => ['nullable', 'array']])['kpi'], 'roi' => $r->input('roi')]),
            default => abort(404),
        };
        return $this->session($r, $id);
    }

    public function commitment(Request $r, int $id, string $action): JsonResponse
    {
        $c = TdCommitment::findOrFail($id); $u = $r->user();
        if ($action === 'sign') $this->sessions->signCommitment($c, $u, $r->input('signature'), $r->input('file_id') ? (int) $r->input('file_id') : null);
        elseif ($action === 'decide') Records::decideCommitment($c, (string) $r->input('action'), $r->input('amount') !== null ? (float) $r->input('amount') : null, $r->input('note'), $u);
        else abort(404);
        return response()->json(['ok' => true]);
    }

    public function commitments(Request $r): JsonResponse
    {
        $this->hr($r);
        return response()->json(['data' => TdCommitment::with('employee:id,code,name,name_en')->latest('id')->limit(300)->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'cost' => $c->cost, 'currency' => $c->currency, 'months' => $c->months,
            'ends_on' => $c->ends_on?->toDateString(), 'status' => $c->status, 'recovery' => $c->recovery, 'due' => $c->status === 'active' ? Td::recovery($c) : $c->recovery, 'mode' => $c->mode,
            'employee' => $c->employee ? ['id' => $c->employee->id, 'code' => $c->employee->code, 'ar' => $c->employee->name, 'en' => $c->employee->name_en] : null, 'body' => $c->body, 'signed_at' => $c->signed_at?->toIso8601String()])]);
    }

    /** التقويم: التنفيذات وبنود الخطة غير المجدولة بعد */
    public function calendar(Request $r): JsonResponse
    {
        $year = (int) $r->query('year', now()->year);
        $s = TdSession::with('program:id,path,delivery,audience')->whereYear('starts_on', $year)->whereNotIn('status', ['cancelled'])->get();
        $items = TdPlanItem::whereHas('plan', fn ($q) => $q->where('year', $year))->whereDoesntHave('program', fn ($q) => $q->whereIn('id', $s->pluck('program_id')))->whereNotIn('status', ['cancelled', 'done'])->with('plan:id,status')->get();
        return response()->json(['year' => $year, 'sessions' => $s->map(fn ($x) => ['id' => $x->id, 'title' => $x->title, 'from' => $x->starts_on->toDateString(), 'to' => $x->ends_on->toDateString(), 'path' => $x->program?->path, 'delivery' => $x->program?->delivery,
            'audience' => $x->program?->audience, 'status' => $x->status, 'location_id' => $x->location_id]),
            'items' => $items->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'month' => $i->month, 'path' => $i->path, 'delivery' => $i->delivery, 'audience' => $i->audience, 'status' => $i->status, 'location_id' => $i->location_id,
                'estimated_cost' => (float) $i->estimated_cost, 'trainees' => $i->trainees, 'priority' => $i->priority, 'objective' => $i->objective, 'program_id' => $i->program_id, 'department_id' => $i->department_id, 'plan_status' => $i->plan?->status, 'pending' => (bool) $i->pending_change]),
            'holidays' => \App\Models\Holiday::whereYear('date', $year)->get(['date', 'name_ar', 'name_en'])->map(fn ($h) => ['date' => $h->date->toDateString(), 'ar' => $h->name_ar, 'en' => $h->name_en])]);
    }

    /** FR-TD-078: ملف iCal للاشتراك من التقويمات الخارجية */
    public function ical(Request $r)
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//UHR//Training//AR'];
        $own = ! Td::manages($r->user());
        $q = TdSession::whereNotIn('status', ['cancelled'])->where('ends_on', '>=', now()->subMonths(3)->toDateString());
        if ($own) $q->whereHas('enrollments', fn ($e) => $e->where('employee_id', $r->user()->employee_id ?? 0)->whereIn('status', ['confirmed', 'completed']));
        foreach ($q->get() as $s) foreach ($s->meetings as $k => $m) {
            $d = str_replace('-', '', $m['date']); $from = str_replace(':', '', $m['from'] ?? '0900').'00'; $to = str_replace(':', '', $m['to'] ?? '1600').'00';
            $lines = array_merge($lines, ['BEGIN:VEVENT', "UID:td-{$s->id}-{$k}@uhr", "DTSTART:{$d}T{$from}", "DTEND:{$d}T{$to}", 'SUMMARY:'.str_replace([',', ';'], ' ', $s->title), 'LOCATION:'.str_replace([',', ';'], ' ', (string) $s->place), 'END:VEVENT']);
        }
        $lines[] = 'END:VCALENDAR';
        return response(implode("\r\n", $lines), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="training.ics"']);
    }

    // ———————————————— الشهادات والتأهيل والأثر ————————————————

    public function certificates(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => ['nullable', 'string']]);
        return response()->json(['data' => TdCertificate::with('employee:id,code,name,name_en')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('id')->limit(300)->get()->map(fn ($c) => ['id' => $c->id, 'no' => $c->no, 'code' => $c->code,
            'type' => $c->type, 'title' => $c->title, 'issuer' => $c->issuer, 'issued_on' => $c->issued_on->toDateString(), 'valid_to' => $c->valid_to?->toDateString(), 'status' => $c->status, 'source' => $c->source, 'file_id' => $c->file_id, 'status_note' => $c->status_note,
            'employee' => $c->employee ? ['id' => $c->employee->id, 'code' => $c->employee->code, 'ar' => $c->employee->name, 'en' => $c->employee->name_en] : null])]);
    }

    public function certificateAction(Request $r, int $id, string $action): JsonResponse
    {
        $c = TdCertificate::findOrFail($id); $u = $r->user();
        $action === 'verify' ? $this->sessions->verifyCert($c, $r->boolean('ok'), $r->input('note'), $u) : ($action === 'revoke' ? $this->sessions->revoke($c, (string) $r->input('reason'), $u) : abort(404));
        return response()->json(['ok' => true]);
    }

    public function certificate(Request $r, int $id): JsonResponse
    {
        $c = TdCertificate::findOrFail($id); $u = $r->user();
        if (! Td::manages($u) && $c->employee_id !== $u->employee_id) abort(403);
        $origin = $r->headers->get('origin') ?: rtrim((string) config('app.frontend_url'), '/');
        return response()->json(['data' => Sessions::verify((string) $c->code) ?? [], 'url' => rtrim($origin, '/').'/td-verify/'.$c->code, 'code' => $c->code, 'no' => $c->no]);
    }

    public function uploadCertificate(Request $r): JsonResponse
    {
        $v = $r->validate(['employee_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:200'], 'issuer' => ['nullable', 'string', 'max:160'], 'competency_id' => ['nullable', 'integer'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'], 'valid_to' => ['nullable', 'date', 'after:issued_on'], 'file_id' => ['required', 'integer']]);
        $e = Employee::findOrFail($v['employee_id'] ?? $r->user()->employee_id);
        $this->sessions->upload($e, $v, $r->user());
        return response()->json(['ok' => true], 201);
    }

    public function qualifications(Request $r): JsonResponse
    {
        $u = $r->user(); $scope = Td::manages($u) ? null : Td::scoped($u)->pluck('id');
        return response()->json(['data' => TdQualification::with('employee:id,code,name,name_en,hire_date,status,manager_id')->when($scope, fn ($q) => $q->whereIn('employee_id', $scope))->latest('id')->limit(200)->get()
            ->map(fn ($q) => ['id' => $q->id, 'employee' => $q->employee ? ['id' => $q->employee->id, 'code' => $q->employee->code, 'ar' => $q->employee->name, 'en' => $q->employee->name_en, 'hire_date' => $q->employee->hire_date?->toDateString(), 'status' => $q->employee->status] : null,
                'stations' => array_map(fn ($s) => $s + ['trainer' => ($t = Employee::find($s['trainer_id'] ?? 0)) ? ['ar' => $t->name, 'en' => $t->name_en] : null, 'department' => ($d = Department::find($s['department_id'] ?? 0)) ? ['ar' => $d->name_ar, 'en' => $d->name_en] : null], $q->stations),
                'status' => $q->status, 'progress' => $q->progress, 'evaluation' => $q->evaluation, 'certificate_id' => $q->certificate_id, 'kind' => $q->kind])]);
    }

    public function qualificationAction(Request $r, int $id, string $action): JsonResponse
    {
        $q = TdQualification::findOrFail($id); $u = $r->user();
        if ($action === 'sign') Records::signStation($q, (int) $r->input('i'), $r->input('note'), $u);
        elseif ($action === 'evaluate') Records::evaluate($q, $r->validate(['recommendation' => ['required', 'in:confirm,extend,not_confirm'], 'skills' => ['nullable', 'array'], 'note' => ['nullable', 'string', 'max:2000'], 'gaps' => ['nullable', 'array']]), $u);
        elseif ($action === 'stations') { $this->hr($r); $q->update(['stations' => $r->validate(['stations' => ['required', 'array', 'min:1']])['stations'], 'progress' => 0]); }
        else abort(404);
        return $this->qualifications($r);
    }

    public function generateQualification(Request $r): JsonResponse
    {
        $this->hr($r);
        $e = Employee::findOrFail($r->validate(['employee_id' => ['required', 'integer']])['employee_id']);
        if (! Records::qualify($e)) throw new ApiException(L::t('للموظف برنامج تأهيل.', 'The employee already has a qualification programme.'), 'exists', 409);
        return $this->qualifications($r);
    }

    public function impacts(Request $r): JsonResponse
    {
        $u = $r->user();
        $rows = TdImpact::with(['enrollment.employee:id,code,name,name_en', 'enrollment.session:id,title,program_id,ends_on'])->when(! Td::manages($u), fn ($q) => $q->where('manager_id', $u->employee_id ?? 0))
            ->when($r->query('status', 'pending') !== 'all', fn ($q) => $q->where('status', $r->query('status', 'pending')))->orderBy('due_on')->limit(300)->get();
        return response()->json(['data' => $rows->map(fn ($i) => ['id' => $i->id, 'point' => $i->point, 'kind' => $i->kind, 'due_on' => $i->due_on->toDateString(), 'status' => $i->status, 'avg' => $i->avg, 'ratings' => $i->ratings, 'barriers' => $i->barriers, 'notes' => $i->notes,
            'overdue' => $i->status === 'pending' && $i->due_on->isPast(), 'employee' => $i->enrollment?->employee ? ['ar' => $i->enrollment->employee->name, 'en' => $i->enrollment->employee->name_en, 'code' => $i->enrollment->employee->code] : null,
            'session' => $i->enrollment?->session ? ['id' => $i->enrollment->session->id, 'title' => $i->enrollment->session->title] : null,
            'items' => $i->kind === 'behavior' ? (TdProgram::whereKey($i->enrollment?->session?->program_id)->value('impact_items') ?: Td::cfg()['impact_items']) : []])]);
    }

    public function impact(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['ratings' => ['nullable', 'array'], 'notes' => ['nullable', 'string', 'max:2000'], 'barriers' => ['nullable', 'string', 'max:2000'], 'before' => ['nullable', 'numeric'], 'after' => ['nullable', 'numeric']]);
        $this->sessions->impact(TdImpact::findOrFail($id), $v, $r->user());
        return $this->impacts($r);
    }

    // ———————————————— CBP والتقارير والإعدادات ————————————————

    public function cbps(Request $r): JsonResponse
    {
        return response()->json(['data' => TdCbp::latest('id')->get()->map(fn ($c) => $c->only(['id', 'title', 'owner', 'description', 'goals', 'status']) + ['starts_on' => $c->starts_on?->toDateString(), 'ends_on' => $c->ends_on?->toDateString(), 'gaps' => Plans::cbpGaps($c)]),
            'plans' => TdPlan::orderByDesc('year')->get(['id', 'year', 'title', 'status'])]);
    }

    public function saveCbp(Request $r, ?int $id = null): JsonResponse
    {
        $v = $r->validate(['title' => ['required', 'string', 'max:200'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'], 'owner' => ['nullable', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:3000'],
            'goals' => ['present', 'array', 'max:20'], 'goals.*.title' => ['required', 'string', 'max:300'], 'status' => ['nullable', 'in:active,closed']]);
        $this->plans->saveCbp($r->all() + $v, $r->user(), $id ? TdCbp::findOrFail($id) : null);
        return $this->cbps($r);
    }

    public function pushCbp(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['goal' => ['required', 'string'], 'initiative' => ['required', 'string'], 'plan_id' => ['required', 'integer']]);
        $this->plans->pushInitiative(TdCbp::findOrFail($id), $v['goal'], $v['initiative'], TdPlan::findOrFail($v['plan_id']), $r->user());
        return $this->cbps($r);
    }

    public function reports(Request $r): JsonResponse
    {
        return response()->json(['data' => Records::reports((int) $r->query('year', now()->year))]);
    }

    public function record(Request $r, int $id): JsonResponse
    {
        $e = Td::scoped($r->user())->findOrFail($id);
        return response()->json(['data' => Records::record($e) + ['employee' => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'hire_date' => $e->hire_date?->toDateString()]]]);
    }

    public function settings(Request $r): JsonResponse
    {
        return response()->json(['data' => Td::cfg(), 'defaults' => Settings::DEFAULTS['training'], 'can' => ['edit' => Perm::can($r->user(), 'settings', 'edit')]]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $v = $r->validate(['mode' => ['required', 'in:hr_only,self_service'], 'probation_block' => ['required', 'boolean'], 'pass_mark' => ['required', 'integer', 'between:1,100'], 'attendance_min' => ['required', 'integer', 'between:1,100'],
            'excellence' => ['required', 'integer', 'between:1,100'], 'ban_threshold' => ['required', 'integer', 'between:1,100'], 'capacity' => ['required', 'array'], 'min_quotes' => ['required', 'integer', 'between:0,10'],
            'commitment_pct' => ['required', 'numeric', 'between:0,1000'], 'commitment_on_request' => ['required', 'boolean'], 'commitment_tiers' => ['required', 'array', 'min:1', 'max:10'], 'commitment_tiers.*.from' => ['required', 'numeric', 'min:0'],
            'commitment_tiers.*.to' => ['required', 'numeric', 'min:0'], 'commitment_tiers.*.months' => ['required', 'integer', 'between:1,120'], 'commitment_currency' => ['required', 'string', 'size:3'], 'recovery' => ['required', 'in:full,prorata'],
            'min_evidence' => ['required', 'integer', 'between:0,5'], 'tna_from_month' => ['required', 'integer', 'between:1,12'], 'tna_to_month' => ['required', 'integer', 'between:1,12'], 'impact_points' => ['required', 'array', 'min:1'],
            'impact_points.*' => ['integer', 'between:7,365'], 'impact_form_point' => ['required', 'integer'], 'impact_pass' => ['required', 'integer', 'between:20,100'], 'remind_days' => ['required', 'integer', 'between:1,60'],
            'escalate_days' => ['required', 'integer', 'between:1,90'], 'survey_min' => ['required', 'integer', 'between:0,100'], 'gap_threshold' => ['required', 'integer', 'between:1,4'], 'weights' => ['required', 'array'],
            'cert_alerts' => ['required', 'array'], 'stage_hours_pct' => ['required', 'integer', 'between:1,100'], 'stage_min_rating' => ['required', 'integer', 'between:1,5'], 'stage_free_max_months' => ['required', 'integer', 'between:1,24'],
            'peak_seasons' => ['present', 'array'], 'cost_types' => ['required', 'array', 'min:1'], 'logistics' => ['present', 'array'], 'survey_items' => ['required', 'array', 'min:3', 'max:15'], 'impact_items' => ['required', 'array', 'min:3', 'max:15'],
            'qualification_templates' => ['present', 'array'], 'commitment_ar' => ['required', 'string', 'max:10000'], 'commitment_en' => ['required', 'string', 'max:10000'],
            // الدفعة 27
            'deliveries' => ['sometimes', 'array', 'min:1', 'max:20'], 'deliveries.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,19}$/', 'distinct'], 'deliveries.*.ar' => ['required', 'string', 'max:60'], 'deliveries.*.en' => ['required', 'string', 'max:60'],
            'audiences' => ['sometimes', 'array', 'min:1', 'max:12'], 'audiences.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,11}$/', 'distinct'], 'audiences.*.ar' => ['required', 'string', 'max:60'], 'audiences.*.en' => ['required', 'string', 'max:60'],
            'quote_gate' => ['sometimes', 'boolean'], 'quote_criteria' => ['sometimes', 'array', 'min:1', 'max:8'], 'quote_criteria.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,19}$/', 'distinct'], 'quote_criteria.*.ar' => ['required', 'string', 'max:60'],
            'quote_criteria.*.en' => ['required', 'string', 'max:60'], 'quote_criteria.*.weight' => ['required', 'integer', 'between:0,100'], 'change_approval' => ['sometimes', 'boolean'], 'trainer_weights' => ['sometimes', 'array'], 'trainer_weights.*' => ['integer', 'between:0,100'],
            'checkin_before' => ['sometimes', 'integer', 'between:0,240'], 'checkin_after' => ['sometimes', 'integer', 'between:0,480'], 'late_after' => ['sometimes', 'integer', 'between:0,120'], 'campaign_remind_days' => ['sometimes', 'integer', 'between:1,30'],
            'campaign_auto_close' => ['sometimes', 'boolean'], 'tna_enforce' => ['sometimes', 'boolean'], 'smart_required' => ['sometimes', 'boolean'], 'email' => ['sometimes', 'boolean'], 'link_days' => ['sometimes', 'integer', 'between:1,60'],
            'idp_from_appraisal' => ['sometimes', 'boolean'], 'cert_templates' => ['sometimes', 'array'], 'cert_templates.*.ar' => ['required', 'string', 'max:80'], 'cert_templates.*.en' => ['required', 'string', 'max:80'],
            'cert_templates.*.body_ar' => ['required', 'string', 'max:2000'], 'cert_templates.*.body_en' => ['required', 'string', 'max:2000'], 'cert_signer' => ['sometimes', 'string', 'max:80'], 'linkedin_share' => ['sometimes', 'boolean']]);
        if (isset($v['quote_criteria']) && array_sum(array_column($v['quote_criteria'], 'weight')) !== 100) throw ApiException::field('quote_criteria', L::t('مجموع أوزان معايير العروض يجب أن يساوي 100.', 'Quote criteria weights must add up to 100.'), 'weights_sum');
        if (isset($v['trainer_weights']) && array_sum($v['trainer_weights']) !== 100) throw ApiException::field('trainer_weights', L::t('مجموع أوزان بطاقة المدرب يجب أن يساوي 100.', 'Trainer scorecard weights must add up to 100.'), 'weights_sum');
        foreach (['deliveries' => 'delivery', 'audiences' => 'audience'] as $k => $col) if (isset($v[$k])) {
            $used = TdProgram::distinct()->pluck($col)->merge(TdPlanItem::distinct()->pluck($col))->filter()->unique()->diff(array_column($v[$k], 'key'));
            if ($used->isNotEmpty()) throw ApiException::field($k, L::t('لا يمكن حذف تصنيف مستعمل: '.$used->implode('، '), 'A category in use cannot be removed: '.$used->implode(', ')), 'in_use');
        }
        $old = Td::cfg();
        $v = array_merge(array_diff_key($old, ['commitment_ar' => 1]), $v);
        Settings::put('training', $v);
        Audit::record('settings.training', null, ['before' => array_diff_key($old, ['commitment_ar' => 1, 'commitment_en' => 1]), 'after' => array_diff_key($v, ['commitment_ar' => 1, 'commitment_en' => 1])]);
        return $this->settings($r);
    }

    public function upload(Request $r): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx,pptx']]);
        $f = $r->file('file');
        $path = $f->store('companies/'.\App\Support\Tenant::id().'/training/'.now()->format('Y/m'), 'local');
        $s = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($f->getClientOriginalName(), 0, 255), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => $r->user()->id]);
        return response()->json(['id' => $s->id, 'name' => $s->name], 201);
    }

    // ———————————————— بوابة الموظف والمدير ————————————————

    public function me(Request $r): JsonResponse
    {
        $u = $r->user(); $e = $u->employee_id ? Employee::find($u->employee_id) : null;
        if (! $e) return response()->json(['record' => null]);
        $team = Employee::current()->where('manager_id', $e->id)->pluck('id');
        $trainer = TdTrainer::where('employee_id', $e->id)->value('id');
        return response()->json(['record' => Records::record($e), 'employee_id' => $e->id, 'mode' => Td::cfg()['mode'],
            'to_sign' => TdCommitment::where('employee_id', $e->id)->where('status', 'generated')->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'cost' => $c->cost, 'currency' => $c->currency, 'months' => $c->months, 'body' => $c->body]),
            'upcoming' => TdEnrollment::with('session')->where('employee_id', $e->id)->whereIn('status', ['nominated', 'pending_commitment', 'confirmed'])->get()->map(fn ($en) => ['id' => $en->id, 'status' => $en->status, 'session' => self::sessionRow($en->session)]),
            'team_nominations' => TdEnrollment::with(['employee:id,code,name,name_en', 'session:id,title,starts_on'])->whereIn('employee_id', $team)->where('status', 'nominated')->whereNull('manager_ok')->get()
                ->map(fn ($en) => ['id' => $en->id, 'employee' => ['ar' => $en->employee->name, 'en' => $en->employee->name_en], 'session' => ['title' => $en->session->title, 'starts_on' => $en->session->starts_on->toDateString()]]),
            'impacts' => TdImpact::where('manager_id', $e->id)->where('status', 'pending')->where('due_on', '<=', now()->addDays(7)->toDateString())->count(),
            'stations' => TdQualification::with('employee:id,name,name_en')->where('status', '!=', 'done')->get()->flatMap(fn ($q) => collect($q->stations)->map(fn ($s, $i) => ['q' => $q->id, 'i' => $i, 'employee' => ['ar' => $q->employee->name, 'en' => $q->employee->name_en]] + $s)
                ->filter(fn ($s) => (int) ($s['trainer_id'] ?? 0) === $e->id && ! $s['signed_at']))->values(),
            'evaluations' => TdQualification::with('employee:id,name,name_en,manager_id')->where('status', '!=', 'done')->where('progress', 100)->get()->filter(fn ($q) => $q->employee?->manager_id === $e->id)
                ->map(fn ($q) => ['id' => $q->id, 'employee' => ['ar' => $q->employee->name, 'en' => $q->employee->name_en]])->values(),
            'trainer_sessions' => $trainer ? TdSession::where('trainer_id', $trainer)->whereNotIn('status', ['closed', 'cancelled'])->get()->map(fn ($s) => self::sessionRow($s)) : [],
            'competencies' => Competency::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en'])->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en]), 'is_manager' => $team->isNotEmpty() || $u->hasRole('manager')]);
    }

    public function meAction(Request $r, string $kind, int $id, string $action): JsonResponse
    {
        $u = $r->user();
        match ("{$kind}.{$action}") {
            'commitments.sign' => $this->sessions->signCommitment(TdCommitment::findOrFail($id), $u, $r->input('signature'), null),
            'enrollments.apply' => $this->sessions->applyPlan(TdEnrollment::findOrFail($id), (array) $r->input('items', []), $r->input('support'), $u),
            'enrollments.withdraw' => $this->sessions->withdraw(TdEnrollment::findOrFail($id), $r->input('reason'), $u),
            'enrollments.manager' => $this->sessions->managerDecision(TdEnrollment::findOrFail($id), $r->boolean('ok'), $u),
            'qualifications.sign' => Records::signStation(TdQualification::findOrFail($id), (int) $r->input('i'), $r->input('note'), $u),
            'qualifications.evaluate' => Records::evaluate(TdQualification::findOrFail($id), $r->validate(['recommendation' => ['required', 'in:confirm,extend,not_confirm'], 'skills' => ['nullable', 'array'], 'note' => ['nullable', 'string', 'max:2000'], 'gaps' => ['nullable', 'array']]), $u),
            default => abort(404),
        };
        return $this->me($r);
    }

    public function checkin(Request $r, string $token): JsonResponse { return response()->json(['data' => $this->sessions->checkin($token, $r->user())]); }

    // ———————————————— عام ————————————————

    public function surveyForm(string $token): JsonResponse
    {
        $s = TdSession::allCompanies()->where('survey_token', $token)->firstOrFail();
        return \App\Support\Tenant::run($s->company_id, fn () => response()->json(['title' => $s->title, 'trainer' => TdTrainer::whereKey($s->trainer_id)->value('name'), 'place' => $s->place, 'from' => $s->starts_on->toDateString(), 'to' => $s->ends_on->toDateString(),
            'open' => in_array($s->status, ['in_progress', 'completed'], true), 'items' => Td::cfg()['survey_items']]));
    }

    public function submitSurvey(Request $r, string $token): JsonResponse
    {
        $s = TdSession::allCompanies()->where('survey_token', $token)->firstOrFail();
        $v = $r->validate(['ratings' => ['required', 'array'], 'answers' => ['nullable', 'array'], 'anonymous' => ['nullable', 'boolean']]);
        \App\Support\Tenant::run($s->company_id, fn () => $this->sessions->survey($token, $v['ratings'], $v['answers'] ?? [], $r->user(), (bool) ($v['anonymous'] ?? true)));
        return response()->json(['ok' => true], 201);
    }

    public function verify(string $code): JsonResponse
    {
        $d = Sessions::verify($code);
        if (! $d) throw new ApiException(L::t('لا توجد شهادة بهذا الرمز.', 'No certificate has this code.'), 'not_found', 404);
        return response()->json(['data' => $d]);
    }
}
