<?php

namespace App\Services\Training;

use App\Exceptions\ApiException;
use App\Models\Competency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\TdCampaign;
use App\Models\TdCertificate;
use App\Models\TdNeed;
use App\Models\TdProgram;
use App\Models\User;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * تحديد وتحليل الاحتياجات (WF-01): حملات TNA بنطاق وإغلاق وتذكير، نموذج F-02 معبأ مسبقاً لكل إدارة، احتياج منفرد من أي مصدر،
 * بوابة تحليل السبب (POL-04)، مصدرا دليل على الأقل (BR-08)، هدف SMART، درجة أولوية، مصفوفة الفجوات، والاحتياجات الآلية من التقييم.
 */
class Needs
{
    private static function min(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    public function save(array $v, User $u, ?TdNeed $n = null): TdNeed
    {
        if ($n && in_array($n->status, ['planned', 'done'], true)) throw new ApiException(L::t('الاحتياج مدرج في الخطة — عدّل بند الخطة.', 'The need is in the plan — edit the plan item.'), 'locked', 409);
        $cfg = Td::cfg();
        if (! Td::manages($u)) {
            if ($cfg['mode'] === 'hr_only' && ! $u->hasRole('manager')) throw new ApiException(L::t('إدخال الاحتياجات من الموارد البشرية في وضع التشغيل الحالي.', 'Needs are entered by HR in the current mode.'), 'hr_only', 403);
            if (! empty($v['employee_id']) && ! Td::inScope($u, (int) $v['employee_id']) && (int) $v['employee_id'] !== $u->employee_id) abort(403);
        }
        if (! empty($v['competency_id']) && ! Competency::whereKey($v['competency_id'])->exists()) throw ApiException::field('competency_id', L::t('كفاءة غير موجودة.', 'Unknown competency.'), 'invalid');
        $e = ! empty($v['employee_id']) ? Employee::find($v['employee_id']) : null;
        $data = array_intersect_key($v, array_flip(['employee_id', 'employees', 'department_id', 'location_id', 'competency_id', 'current_level', 'required_level', 'title', 'path', 'level', 'factors', 'cause',
            'evidence', 'evidence_override', 'objective', 'note', 'program_id', 'estimated_cost', 'preferred_date', 'campaign_id']));
        if ($e) { $data['department_id'] ??= $e->department_id; $data['location_id'] ??= $e->location_id; }
        if ($e && empty($data['required_level']) && ! empty($data['competency_id'])) $data['required_level'] = Td::required($e)[$data['competency_id']] ?? null;
        if ($e && empty($data['current_level']) && ! empty($data['competency_id'])) $data['current_level'] = Td::current($e)[$data['competency_id']] ?? null;
        $data['score'] = Td::score($data['factors'] ?? []);
        $data['priority'] = $v['priority'] ?? Td::priorityOf($data['score']);
        if (! $n) {
            $data += ['ref' => 'TN-'.now()->year.'-'.str_pad((string) Counters::next('td-need-'.now()->year), 4, '0', STR_PAD_LEFT), 'source' => $v['source'] ?? 'request', 'source_ref' => $v['source_ref'] ?? null,
                'status' => $v['status'] ?? 'submitted', 'created_by' => $u->id];
            $n = TdNeed::create($data);
            if (! Td::manages($u)) Notify::hr("احتياج تدريبي جديد: {$n->title}", "New training need: {$n->title}", 'training:needs');
        } else $n->update($data);
        return $n->fresh();
    }

    /** POL-04 + BR-08/09: الاعتماد يتطلب سبب «معرفة أو مهارة» ومصدري دليل على الأقل (أو تجاوزاً مبرراً) */
    public function decide(TdNeed $n, string $action, ?string $note, User $u): TdNeed
    {
        if (! Td::manages($u)) abort(403);
        if (! in_array($n->status, ['suggested', 'submitted', 'rejected', 'not_training'], true)) throw new ApiException(L::t('لا يمكن تغيير هذا الاحتياج الآن.', 'This need cannot change now.'), 'locked', 409);
        if ($action === 'approve') {
            if (! $n->cause) throw ApiException::field('cause', L::t('أجب عن أسئلة تحليل السبب أولاً.', 'Answer the cause-analysis questions first.'), 'cause_required');
            if ($n->cause !== 'knowledge') {
                $n->update(['status' => 'not_training', 'note' => $note ?? $n->note]);
                return $n;   // يُغلق كتوصية إدارية ولا يدخل الخطة
            }
            $min = (int) Td::cfg()['min_evidence'];
            if (count($n->evidence ?? []) < $min && mb_strlen(trim((string) $n->evidence_override)) < self::min())
                throw ApiException::field('evidence', L::t("الاعتماد يتطلب {$min} مصادر دليل على الأقل، أو مبرراً موثقاً.", "Approval needs at least {$min} evidence sources, or a documented justification."), 'evidence_required');
            $n->update(['status' => 'approved', 'note' => $note ?? $n->note]);
        } elseif ($action === 'reject') {
            if (mb_strlen(trim((string) $note)) < self::min()) throw ApiException::field('note', L::t('اكتب سبب الرفض.', 'Write the reason.'), 'reason_required');
            $n->update(['status' => 'rejected', 'note' => $note]);
        } else throw new ApiException(L::t('إجراء غير معروف.', 'Unknown action.'), 'invalid', 422);
        Audit::record('td_need.'.$action, $n);
        if ($n->created_by && $n->created_by !== $u->id) Notify::user($n->created_by, ($n->status === 'approved' ? 'اعتُمد الاحتياج التدريبي: ' : 'لم يُعتمد الاحتياج التدريبي: ').$n->title, ($n->status === 'approved' ? 'Training need approved: ' : 'Training need not approved: ').$n->title, 'training');
        return $n;
    }

    /** FR-TD-020: حملة TNA بنطاق وتاريخ إغلاق، وإشعار مدراء الإدارات المعنية */
    public function campaign(array $v, User $u): TdCampaign
    {
        if (! Td::manages($u)) abort(403);
        $c = TdCampaign::create(['title' => $v['title'], 'year' => $v['year'], 'scope' => $v['scope'] ?? 'company', 'scope_ids' => $v['scope_ids'] ?? null,
            'opens_on' => $v['opens_on'] ?? now()->toDateString(), 'closes_on' => $v['closes_on'], 'status' => 'open', 'responses' => [], 'created_by' => $u->id]);
        foreach ($this->campaignDepartments($c) as $d) if ($d->head_id && ($uid = User::where('employee_id', $d->head_id)->value('id')))
            Notify::user($uid, "حملة تحديد الاحتياجات التدريبية {$c->year}: عبّئ احتياجات إدارتك قبل {$c->closes_on->toDateString()}", "Training needs campaign {$c->year}: fill in your department's needs before {$c->closes_on->toDateString()}", 'training-needs');
        return $c;
    }

    public function campaignDepartments(TdCampaign $c)
    {
        return Department::where('active', true)->when($c->scope === 'department', fn ($q) => $q->whereIn('id', $c->scope_ids ?? []))
            ->when($c->scope === 'branch', fn ($q) => $q->whereHas('employees', fn ($e) => $e->whereIn('location_id', $c->scope_ids ?? [])))->get();
    }

    /** FR-TD-021: نموذج F-02 معبأ مسبقاً: موظفو الإدارة، فجوات الكفاءات، الشهادات المنتهية أو القريبة */
    public function prefill(Department $d): array
    {
        $comps = Competency::where('active', true)->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $emps = Employee::current()->where('department_id', $d->id)->orderBy('name')->get();
        $gaps = [];
        foreach ($emps as $e) {
            $req = Td::required($e); $cur = Td::current($e);
            foreach ($req as $cid => $lv) if (($cur[$cid] ?? 0) + (int) Td::cfg()['gap_threshold'] <= $lv && isset($comps[$cid]))
                $gaps[] = ['employee_id' => $e->id, 'employee' => ['ar' => $e->name, 'en' => $e->name_en], 'competency_id' => $cid, 'competency' => ['ar' => $comps[$cid]->name_ar, 'en' => $comps[$cid]->name_en], 'current' => $cur[$cid] ?? null, 'required' => $lv];
        }
        $certs = TdCertificate::whereIn('employee_id', $emps->pluck('id'))->where('status', 'valid')->whereNotNull('valid_to')->where('valid_to', '<=', now()->addDays(90)->toDateString())->get()
            ->map(fn ($c) => ['employee_id' => $c->employee_id, 'title' => $c->title, 'valid_to' => $c->valid_to->toDateString()]);
        return ['employees' => $emps->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]), 'gaps' => $gaps, 'certificates' => $certs];
    }

    /** إرسال نموذج إدارة ضمن حملة: بنود F-02 تُنشأ احتياجات «مقدّمة» */
    public function submitCampaign(TdCampaign $c, Department $d, array $items, User $u): int
    {
        if ($c->status !== 'open' || $c->closes_on->isPast()) throw new ApiException(L::t('الحملة مغلقة.', 'The campaign is closed.'), 'closed', 409);
        if (! Td::manages($u) && ($u->employee_id ?? 0) !== $d->head_id) abort(403);
        $n = 0;
        DB::transaction(function () use ($c, $d, $items, $u, &$n) {
            foreach ($items as $it) {
                $this->save($it + ['department_id' => $d->id, 'campaign_id' => $c->id, 'source' => 'campaign', 'level' => $it['level'] ?? (count($it['employees'] ?? []) > 1 ? 'job' : 'individual')], $u);
                $n++;
            }
            $r = $c->responses ?? []; $r[$d->id] = now()->toIso8601String(); $c->update(['responses' => $r]);
        });
        return $n;
    }

    public function closeCampaign(TdCampaign $c, User $u): TdCampaign { if (! Td::manages($u)) abort(403); $c->update(['status' => 'closed']); return $c; }

    /** 10.1: اعتماد تقييم أداء ← احتياجات مقترحة آلياً لكل كفاءة دون المستوى المطلوب للوظيفة */
    public function fromAppraisal(PerformanceReview $r): int
    {
        $e = Employee::find($r->employee_id); if (! $e) return 0;
        $req = Td::required($e); if (! $req) return 0;
        $scale = max(1, (int) Settings::merged('performance')['scale']);
        $th = (int) Td::cfg()['gap_threshold']; $n = 0;
        $comps = Competency::whereIn('id', array_keys($req))->get()->keyBy('id');
        foreach ($req as $cid => $lv) {
            $tot = $r->ratings[$cid]['total'] ?? $r->ratings[(string) $cid]['total'] ?? null;
            if ($tot === null) continue;
            $have = (int) round((float) $tot * 5 / $scale);
            Td::setLevel($e, $cid, $have, 'appraisal', 'review:'.$r->id);
            if ($lv - $have < $th || ! isset($comps[$cid])) continue;
            if (TdNeed::where('employee_id', $e->id)->where('competency_id', $cid)->whereNotIn('status', ['done', 'rejected', 'not_training'])->exists()) continue;
            TdNeed::create(['ref' => 'TN-'.now()->year.'-'.str_pad((string) Counters::next('td-need-'.now()->year), 4, '0', STR_PAD_LEFT), 'employee_id' => $e->id, 'department_id' => $e->department_id, 'location_id' => $e->location_id,
                'competency_id' => $cid, 'current_level' => $have, 'required_level' => $lv, 'title' => $comps[$cid]->name_ar, 'path' => 'training', 'level' => 'individual',
                'source' => 'appraisal', 'source_ref' => 'review:'.$r->id, 'evidence' => ['appraisal'], 'status' => 'suggested', 'factors' => ['gap' => min(5, $lv - $have + 1), 'impact' => 3, 'affected' => 1, 'urgency' => 2],
                'score' => Td::score(['gap' => min(5, $lv - $have + 1), 'impact' => 3, 'affected' => 1, 'urgency' => 2]), 'priority' => 'normal']);
            $n++;
        }
        return $n;
    }

    /** FR-TD-027: مصفوفة الفجوات — موظفون × كفاءات */
    public function matrix(User $u, array $f): array
    {
        $emps = Td::scoped($u)->whereNotIn('status', ['terminated'])->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($f['branch'] ?? null, fn ($q, $v) => $q->where('location_id', $v))->when($f['job'] ?? null, fn ($q, $v) => $q->where('job_id', $v))->orderBy('name')->limit(200)->get();
        $jc = \App\Models\JobCompetency::whereIn('job_id', $emps->pluck('job_id')->filter()->unique())->get()->groupBy('job_id');
        $cur = \App\Models\EmployeeCompetency::whereIn('employee_id', $emps->pluck('id'))->get()->groupBy('employee_id');
        $cids = $jc->flatten()->pluck('competency_id')->unique()->values();
        $comps = Competency::whereIn('id', $cids)->orderBy('sort')->get(['id', 'name_ar', 'name_en']);
        return ['competencies' => $comps->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en]),
            'rows' => $emps->map(function ($e) use ($jc, $cur) {
                $req = ($jc[$e->job_id] ?? collect())->pluck('level', 'competency_id'); $have = ($cur[$e->id] ?? collect())->pluck('level', 'competency_id');
                return ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'cells' => $req->map(fn ($lv, $cid) => ['required' => (int) $lv, 'current' => isset($have[$cid]) ? (int) $have[$cid] : null])];
            })];
    }

    /** FR-TD-028: احتياجات معتمدة متشابهة (نفس الكفاءة أو البرنامج) مجمّعة مع اقتراح برنامج من المكتبة */
    public static function groups(): array
    {
        $needs = TdNeed::with(['competency', 'program'])->where('status', 'approved')->get();
        return $needs->groupBy(fn ($n) => $n->program_id ? 'p'.$n->program_id : ($n->competency_id ? 'c'.$n->competency_id : 'n'.$n->id))->map(function ($g) {
            $first = $g->first();
            $prog = $first->program ?? ($first->competency_id ? TdProgram::where('active', true)->whereJsonContains('competencies', [['id' => $first->competency_id]])->first() : null);
            $people = $g->flatMap(fn ($n) => $n->employee_id ? [$n->employee_id] : ($n->employees ?? []))->unique()->values();
            return ['key' => $g->keys()->first(), 'title' => $prog?->title ?? ($first->competency?->name_ar ?? $first->title), 'ids' => $g->pluck('id')->values(), 'count' => $people->count(),
                'program' => $prog ? ['id' => $prog->id, 'title' => $prog->title, 'code' => $prog->code] : null, 'path' => $first->path, 'score' => round($g->sum('score'), 2), 'cost' => $g->sum('estimated_cost')];
        })->sortByDesc('score')->values()->all();
    }
}
