<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referrals\Rewards;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** M12 — الإحالات والمكافآت (ReferralsHR في الديمو): لوحة المتصدرين، روابط الإحالة، طلبات الاستبدال، المتجر */
class ReferralsController extends Controller
{
    public function __construct(private Rewards $rewards) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json(['leaderboard' => Rewards::leaderboard(10), 'funnel' => Rewards::funnel(),
            'links' => Referral::with(['employee', 'jobOpening', 'candidate'])->latest('id')->limit(300)->get()->map(fn ($r) => Rewards::referralRow($r)),
            'redemptions' => Redemption::with(['employee', 'reward'])->orderByRaw("case when status = 'pending' then 0 else 1 end")->latest('id')->limit(200)->get()->map(fn ($r) => Rewards::redemptionRow($r)),
            'rewards' => Reward::orderBy('cost')->get()->map(fn ($w) => Rewards::rewardRow($w)),
            'stages' => Settings::rule('referral_stages'), 'hard_to_fill_x' => (int) Settings::rule('hard_to_fill_x'), 'kinds' => Rewards::KINDS,
            'reject_min' => (int) Settings::policy('leave_rules')['reject_min'], 'can' => ['edit' => Perm::can($u, 'referrals', 'edit')]]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $r = $this->rewards->decide(Redemption::findOrFail($id), $request->user(), (bool) $d['approve'], $d['note'] ?? null);
        return response()->json(['data' => Rewards::redemptionRow($r->load(['employee', 'reward']))]);
    }

    public function saveReward(Request $request, ?int $id = null): JsonResponse
    {
        $w = $this->rewards->saveReward($id ? Reward::findOrFail($id) : null, $request->all());
        return response()->json(['data' => Rewards::rewardRow($w)], $id ? 200 : 201);
    }

    public function updateReward(Request $request, int $id): JsonResponse { return $this->saveReward($request, $id); }
}
