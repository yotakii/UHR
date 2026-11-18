<?php

namespace App\Services\Training;

use App\Exceptions\ApiException;
use App\Models\Competency;
use App\Models\Employee;
use App\Models\TdCertificate;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdQualification;
use App\Models\TdSession;
use App\Models\TdSurvey;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use Carbon\Carbon;

/**
 * التأهيل F-01 (WF-05)، سجل الموظف التدريبي F-06، التعهدات عند الاستقالة (POL-11 / FR-TD-064)، والتقارير ومؤشرات ISO 30414.
 */
class Records
{
    // ———————————————— التأهيل ————————————————

    /** employee.hired ← برنامج تأهيل من قالب الوظيفة أو الإدارة (أو محطة واحدة في إدارته) */
    public static function qualify(Employee $e): ?TdQualification
    {
        if (TdQualification::where('employee_id', $e->id)->exists()) return null;
        $tpl = collect(Td::cfg()['qualification_templates'])->first(fn ($t) => in_array($e->getAttributes()['job_id'] ?? 0, $t['job_ids'] ?? [], true))
            ?? collect(Td::cfg()['qualification_templates'])->first(fn ($t) => in_array($e->department_id, $t['department_ids'] ?? [], true));
        $start = Carbon::parse($e->hire_date ?? now()); $stations = [];
        foreach ($tpl['stations'] ?? [['department_id' => $e->department_id, 'days' => 5, 'topics' => 'التعريف بالعمل والأنظمة والإجراءات']] as $st) {
            $days = max(1, (int) ($st['days'] ?? 5));
            $head = \App\Models\Department::whereKey($st['department_id'] ?? 0)->value('head_id');
            $stations[] = ['department_id' => $st['department_id'] ?? null, 'trainer_id' => $st['trainer_id'] ?? ($head ?: $e->manager_id), 'topics' => $st['topics'] ?? '', 'from' => $start->toDateString(),
                'to' => $start->copy()->addDays($days - 1)->toDateString(), 'note' => null, 'signed_at' => null, 'signed_by' => null];
            $start->addDays($days);
        }
        return TdQualification::create(['employee_id' => $e->id, 'template' => $tpl['key'] ?? null, 'stations' => $stations, 'status' => 'planned', 'progress' => 0]);
    }

    /** FR-TD-111: مدرب المحطة يسجل ملاحظاته ويوقّع على إتمام محاورها */
    public static function signStation(TdQualification $q, int $i, ?string $note, User $u): TdQualification
    {
        $st = $q->stations; if (! isset($st[$i])) abort(404);
        if (! Td::manages($u) && ($u->employee_id === null || (int) $u->employee_id !== (int) $st[$i]['trainer_id'])) abort(403);
        if ($st[$i]['signed_at']) throw new ApiException(L::t('وُقّعت هذه المحطة.', 'Already signed.'), 'signed', 409);
        $st[$i] = array_merge($st[$i], ['note' => $note, 'signed_at' => now()->toIso8601String(), 'signed_by' => $u->displayName('ar')]);
        $done = count(array_filter($st, fn ($s) => $s['signed_at']));
        $q->update(['stations' => $st, 'progress' => (int) round($done * 100 / count($st)), 'status' => $done === count($st) ? 'running' : 'running']);
        return $q;
    }

    /** تقييم نهاية التأهيل (المدير المباشر) ← شهادة إتمام التأهيل، والفجوة المتبقية احتياج، والتوصية تغذي قرار التثبيت */
    public static function evaluate(TdQualification $q, array $v, User $u): TdQualification
    {
        $e = Employee::find($q->employee_id);
        if (! Td::manages($u) && ($u->employee_id === null || $u->employee_id !== $e->manager_id)) abort(403);
        if ($q->progress < 100) throw new ApiException(L::t('لم تُوقَّع كل المحطات بعد.', 'Not all stations are signed yet.'), 'stations_pending', 409);
        if (! in_array($v['recommendation'] ?? '', ['confirm', 'extend', 'not_confirm'], true)) throw ApiException::field('recommendation', L::t('اختر التوصية.', 'Choose the recommendation.'), 'required');
        $cert = app(Sessions::class)->issue(['employee_id' => $e->id, 'type' => 'qualification', 'title' => 'إتمام برنامج التأهيل']);
        $q->update(['evaluation' => ['skills' => $v['skills'] ?? [], 'recommendation' => $v['recommendation'], 'note' => $v['note'] ?? null, 'by' => $u->displayName('ar'), 'at' => now()->toIso8601String()], 'status' => 'done', 'certificate_id' => $cert->id]);
        foreach ($v['gaps'] ?? [] as $cid) if (Competency::whereKey($cid)->exists()) app(Needs::class)->save(['employee_id' => $e->id, 'competency_id' => (int) $cid, 'title' => Competency::whereKey($cid)->value('name_ar'), 'path' => 'qualification', 'source' => 'event', 'source_ref' => 'qualification:'.$q->id, 'status' => 'suggested'], $u);
        $e->log('اكتمل برنامج التأهيل — التوصية: '.['confirm' => 'التثبيت', 'extend' => 'تمديد التجربة', 'not_confirm' => 'عدم التثبيت'][$v['recommendation']], 'Qualification programme completed — recommendation: '.$v['recommendation'], 'info');
        return $q;
    }

    // ———————————————— التعهدات والاستقالة ————————————————

    /** employee.resignation_requested ← التعهدات السارية «مُخَلّ بها» بمبلغ مستحق بانتظار قرار الموارد البشرية */
    public static function onResignation(Employee $e, int $resignationId): float
    {
        $sum = 0.0;
        foreach (TdCommitment::where('employee_id', $e->id)->where('status', 'active')->get() as $c) {
            $amt = Td::recovery($c);
            if ($amt <= 0) { $c->update(['status' => 'fulfilled']); continue; }
            $c->update(['status' => 'breached', 'recovery' => $amt, 'resignation_id' => $resignationId]);
            $sum += $amt;
        }
        if ($sum > 0) Notify::hr("طلب استقالة من {$e->name} وعليه التزام تدريب سارٍ — المبلغ المستحق ".number_format($sum, 2), "{$e->name_en} resigned with an active training commitment — amount due ".number_format($sum, 2), 'training:commitments');
        return $sum;
    }

    /** سحب الاستقالة ← التعهدات تعود سارية */
    public static function onWithdrawal(int $resignationId): void
    {
        TdCommitment::where('resignation_id', $resignationId)->where('status', 'breached')->update(['status' => 'active', 'recovery' => null, 'resignation_id' => null]);
    }

    /** قرار الاسترداد (BR-13): استرداد ← يُخصم في المخالصة، أو إعفاء بقرار موثق */
    public static function decideCommitment(TdCommitment $c, string $action, ?float $amount, ?string $note, User $u): TdCommitment
    {
        if (! Td::manages($u) || ! $u->hasRole('hr_director', 'admin')) abort(403);
        if ($c->status !== 'breached') throw new ApiException(L::t('التعهد ليس بانتظار قرار.', 'The commitment is not awaiting a decision.'), 'invalid', 409);
        if ($action === 'waive' && mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الإعفاء.', 'Write the reason.'), 'reason_required');
        $c->update(['status' => $action === 'recover' ? 'recovered' : 'waived', 'recovery' => $action === 'recover' ? ($amount ?? $c->recovery) : 0, 'decision_note' => $note, 'decided_by' => $u->id, 'decided_at' => now()]);
        Audit::record('td_commitment.'.$action, $c);
        return $c;
    }

    /** المبلغ المعتمد للاسترداد في مخالصة الاستقالة (F-33) */
    public static function settlementDeduction(int $resignationId): float
    {
        return (float) TdCommitment::where('resignation_id', $resignationId)->where('status', 'recovered')->sum('recovery');
    }

    // ———————————————— السجل التدريبي F-06 ————————————————

    public static function record(Employee $e): array
    {
        $ens = TdEnrollment::with(['session.program:id,title,path,delivery,hours', 'session.trainer:id,name,name_en', 'session.center:id,name'])->where('employee_id', $e->id)->latest('id')->get();
        $certs = TdCertificate::where('employee_id', $e->id)->latest('issued_on')->get();
        $req = Td::required($e); $cur = Td::current($e);
        $comps = Competency::whereIn('id', array_unique(array_merge(array_keys($req), array_keys($cur))))->get(['id', 'name_ar', 'name_en']);
        $year = now()->year;
        $done = $ens->where('result', 'passed');
        return [
            'enrollments' => $ens->map(fn ($en) => ['id' => $en->id, 'title' => $en->session?->title, 'path' => $en->session?->program?->path, 'delivery' => $en->session?->program?->delivery, 'hours' => (float) ($en->session?->program?->hours ?? 0),
                'from' => $en->session?->starts_on?->toDateString(), 'to' => $en->session?->ends_on?->toDateString(), 'provider' => $en->session?->center?->name ?? $en->session?->trainer?->name, 'place' => $en->session?->place,
                'status' => $en->status, 'result' => $en->result, 'attendance_pct' => $en->attendance_pct, 'post_score' => $en->post_score, 'cost' => $en->cost, 'currency' => $en->session?->currency,
                'apply_plan' => $en->apply_plan, 'session_id' => $en->session_id, 'impact' => TdImpact::where('enrollment_id', $en->id)->where('kind', 'behavior')->value('avg')])->values(),
            'certificates' => $certs->map(fn ($c) => ['id' => $c->id, 'no' => $c->no, 'code' => $c->code, 'type' => $c->type, 'title' => $c->title, 'issuer' => $c->issuer, 'issued_on' => $c->issued_on->toDateString(),
                'valid_to' => $c->valid_to?->toDateString(), 'status' => $c->status, 'source' => $c->source, 'file_id' => $c->file_id])->values(),
            'commitments' => TdCommitment::where('employee_id', $e->id)->latest('id')->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'cost' => $c->cost, 'currency' => $c->currency, 'months' => $c->months,
                'ends_on' => $c->ends_on?->toDateString(), 'status' => $c->status, 'days_left' => $c->ends_on && $c->status === 'active' ? max(0, now()->startOfDay()->diffInDays($c->ends_on, false)) : null,
                'due' => $c->status === 'active' ? Td::recovery($c) : $c->recovery, 'body' => $c->body, 'signed_at' => $c->signed_at?->toIso8601String()])->values(),
            'needs' => TdNeed::where('employee_id', $e->id)->whereNotIn('status', ['done', 'rejected'])->latest('id')->get(['id', 'ref', 'title', 'status', 'priority', 'path'])->values(),
            'competencies' => $comps->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en, 'required' => $req[$c->id] ?? null, 'current' => $cur[$c->id] ?? null])->values(),
            'qualification' => ($q = TdQualification::where('employee_id', $e->id)->first()) ? ['id' => $q->id, 'status' => $q->status, 'progress' => $q->progress, 'stations' => $q->stations, 'evaluation' => $q->evaluation] : null,
            'totals' => ['hours_year' => (float) $done->filter(fn ($x) => $x->session?->starts_on?->year === $year)->sum(fn ($x) => (float) ($x->session?->program?->hours ?? 0)),
                'hours_all' => (float) $done->sum(fn ($x) => (float) ($x->session?->program?->hours ?? 0)), 'cost_year' => (float) $ens->filter(fn ($x) => $x->session?->starts_on?->year === $year)->sum('cost'), 'cost_all' => (float) $ens->sum('cost'), 'courses' => $done->count()],
        ];
    }

    // ———————————————— التقارير ————————————————

    public static function reports(int $year): array
    {
        $from = "{$year}-01-01"; $to = "{$year}-12-31";
        $sessions = TdSession::with('program:id,path,hours,delivery')->whereBetween('starts_on', [$from, $to])->whereNotIn('status', ['cancelled'])->get();
        $ids = $sessions->pluck('id');
        $ens = TdEnrollment::whereIn('session_id', $ids)->get();
        $done = $ens->where('status', 'completed');
        $head = Employee::current()->count();
        $participants = $done->pluck('employee_id')->unique()->count();
        $hours = (float) $done->where('result', 'passed')->sum(fn ($en) => (float) ($sessions->firstWhere('id', $en->session_id)?->program?->hours ?? 0));
        $cost = (float) $sessions->sum(fn ($s) => Sessions::totalCost($s));
        $l1 = TdSurvey::whereIn('session_id', $ids)->avg('avg');
        $gain = $done->filter(fn ($e) => $e->pre_score !== null && $e->post_score !== null)->avg(fn ($e) => $e->post_score - $e->pre_score);
        $l3 = TdImpact::whereIn('enrollment_id', $done->pluck('id'))->where('kind', 'behavior')->where('status', 'done')->avg('avg');
        $byDept = Employee::whereIn('id', $done->pluck('employee_id'))->selectRaw('department_id, count(*) n')->groupBy('department_id')->pluck('n', 'department_id');
        $headDept = Employee::current()->selectRaw('department_id, count(*) n')->groupBy('department_id')->pluck('n', 'department_id');
        return [
            'kpis' => ['sessions' => $sessions->count(), 'participants' => $participants, 'participation' => $head ? round($participants * 100 / $head, 1) : 0, 'hours' => $hours, 'hours_per_employee' => $head ? round($hours / $head, 2) : 0,
                'cost' => round($cost, 2), 'cost_per_employee' => $head ? round($cost / $head, 2) : 0, 'pass_rate' => $done->count() ? round($done->where('result', 'passed')->count() * 100 / $done->count(), 1) : null,
                'l1' => $l1 !== null ? round((float) $l1, 2) : null, 'l2_gain' => $gain !== null ? round((float) $gain, 1) : null, 'l3' => $l3 !== null ? round((float) $l3, 1) : null],
            'by_path' => $sessions->groupBy(fn ($s) => $s->program?->path)->map(fn ($g) => ['sessions' => $g->count(), 'cost' => round((float) $g->sum(fn ($s) => Sessions::totalCost($s)), 2)]),
            'by_delivery' => $sessions->groupBy(fn ($s) => $s->program?->delivery)->map->count(),
            'fairness' => $headDept->map(fn ($n, $d) => ['headcount' => $n, 'trained' => (int) ($byDept[$d] ?? 0), 'pct' => $n ? round(($byDept[$d] ?? 0) * 100 / $n, 1) : 0]),
            'needs' => TdNeed::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'), 'needs_source' => TdNeed::selectRaw('source, count(*) n')->groupBy('source')->pluck('n', 'source'),
            'expiring' => TdCertificate::with('employee:id,name,name_en')->where('status', 'valid')->whereNotNull('valid_to')->whereBetween('valid_to', [now()->toDateString(), now()->addDays(90)->toDateString()])->orderBy('valid_to')->get()
                ->map(fn ($c) => ['title' => $c->title, 'valid_to' => $c->valid_to->toDateString(), 'employee' => $c->employee ? ['ar' => $c->employee->name, 'en' => $c->employee->name_en] : null]),
            'commitments' => ['active' => TdCommitment::where('status', 'active')->count(), 'at_risk' => round((float) TdCommitment::where('status', 'active')->get()->sum(fn ($c) => Td::recovery($c)), 2),
                'breached' => TdCommitment::where('status', 'breached')->count()],
            'impact_followup' => ['due' => TdImpact::where('status', 'pending')->where('due_on', '<=', now()->toDateString())->count(), 'done' => TdImpact::where('status', 'done')->count()],
        ];
    }
}
