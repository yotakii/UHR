<?php

namespace App\Http\Controllers\Api\Employee;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\InternalApplication;
use App\Models\JobOpening;
use App\Models\PointLog;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Recruitment\OfferResponse;
use App\Services\Referrals\InternalMobility;
use App\Services\Referrals\Rewards;
use App\Services\Workflow\Workflow;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** بوابة الموظف (M12): «المكافآت» — الرصيد، روابط الإحالة، المتجر والاستبدال، لوحة المتصدرين؛ و«ترشيح نفسي» F-27 */
class MyRewardsController extends Controller
{
    public function __construct(private Rewards $rewards, private InternalMobility $mobility) {}

    public function index(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $mine = Referral::with(['employee', 'jobOpening', 'candidate'])->where('employee_id', $e->id)->get()->keyBy('job_opening_id');
        $jobs = JobOpening::with('department')->where('status', 'open')->where('visibility', '!=', 'internal')->orderByDesc('hard_to_fill')->orderByDesc('id')->get()
            ->map(function ($j) use ($mine) {
                $r = $mine->get($j->id);
                return ['id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en], 'dept' => $j->department ? ['ar' => $j->department->name_ar, 'en' => $j->department->name_en] : null,
                    'hard_to_fill' => (bool) $j->hard_to_fill, 'referral' => $r ? ['code' => $r->code, 'link' => Rewards::link($r), 'clicks' => (int) $r->clicks, 'status' => $r->status] : null];
            });
        return response()->json(['points' => (int) $e->points, 'lifetime' => (int) $e->lifetime_points, 'jobs' => $jobs, 'leaderboard' => Rewards::leaderboard(6),
            'stages' => Settings::rule('referral_stages'), 'hard_to_fill_x' => (int) Settings::rule('hard_to_fill_x'),
            'rewards' => Reward::where('active', true)->orderBy('cost')->get()->map(fn ($w) => Rewards::rewardRow($w)),
            'redemptions' => Redemption::with(['employee', 'reward'])->where('employee_id', $e->id)->latest('id')->limit(30)->get()->map(fn ($r) => Rewards::redemptionRow($r)),
            'log' => PointLog::where('employee_id', $e->id)->latest('at')->limit(30)->get()->map(fn ($l) => ['points' => (int) $l->points, 'reason' => ['ar' => $l->reason_ar, 'en' => $l->reason_en], 'at' => $l->at?->toIso8601String()]),
            'referrals' => $mine->values()->map(fn ($r) => Rewards::referralRow($r))]);
    }

    public function share(Request $request): JsonResponse
    {
        $j = JobOpening::findOrFail((int) $request->validate(['job_id' => ['required', 'integer']])['job_id']);
        $r = $this->rewards->share($this->me($request), $j)->load('jobOpening');
        return response()->json(['code' => $r->code, 'link' => Rewards::link($r)]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $w = Reward::findOrFail((int) $request->validate(['reward_id' => ['required', 'integer']])['reward_id']);
        $this->rewards->redeem($this->me($request), $w);
        return $this->index($request);
    }

    // ── الاستقطاب الداخلي F-27 ──

    public function internal(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $apps = InternalApplication::with(['jobOpening', 'employee', 'candidate.offer', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'])->where('employee_id', $e->id)->latest('id')->get();
        return response()->json(['eligibility' => InternalMobility::eligibility($e), 'jobs' => InternalMobility::jobs($e),
            'rules' => ['min_months' => (int) Settings::rule('internal_min_months'), 'no_probation' => (bool) Settings::rule('internal_no_probation'), 'clean_months' => (int) Settings::rule('internal_clean_months')],
            'data' => $apps->map(fn ($ia) => InternalMobility::row($ia, $request->user()) + ['offer' => $this->offer($ia->candidate)])]);
    }

    public function applyInternal(Request $request): JsonResponse
    {
        $d = $request->validate(['job_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:2000']], [], ['reason' => L::t('أسباب ودوافع التقدم', 'Reasons')]);
        $this->mobility->apply($this->me($request), JobOpening::findOrFail($d['job_id']), $d['reason'], $request->user());
        return $this->internal($request);
    }

    public function cancelInternal(Request $request, int $id): JsonResponse
    {
        $ia = InternalApplication::where('employee_id', $this->me($request)->id)->findOrFail($id);
        app(Workflow::class)->cancel($ia, $request->user());
        return $this->internal($request);
    }

    /** رد الموظف على عرض النقل الداخلي (F-29) — يفتح قرار التعيين F-31 */
    public function offerResponse(Request $request, int $id, OfferResponse $service): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:accepted,declined,negotiation'], 'signature' => ['nullable', 'string'], 'note' => ['nullable', 'string', 'max:1000']]);
        $ia = InternalApplication::where('employee_id', $this->me($request)->id)->whereNotNull('candidate_id')->findOrFail($id);
        $service->respond(Candidate::findOrFail($ia->candidate_id), $d['status'], $d['signature'] ?? null, $d['note'] ?? null);
        return $this->internal($request);
    }

    private function offer(?Candidate $c): ?array
    {
        $o = $c?->offer;
        if (! $o || ! in_array($o->status, ['sent', 'accepted', 'declined', 'negotiation'], true)) return null;
        return ['status' => $o->status, 'title' => $o->title ?: null, 'total' => $o->total(), 'currency' => $o->currency, 'start_date' => $o->start_date?->toDateString(), 'stage' => $c->stage];
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        if (! $id) throw new ApiException(L::t('حسابك غير مرتبط بملف موظف.', 'Your account is not linked to an employee file.'), 'no_employee', 403);
        return Employee::findOrFail($id);
    }
}
