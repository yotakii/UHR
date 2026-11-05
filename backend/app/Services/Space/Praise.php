<?php

namespace App\Services\Space;

use App\Exceptions\ApiException;
use App\Models\Community;
use App\Models\Employee;
use App\Models\Praise as PraiseRow;
use App\Models\SpacePost;
use App\Models\User;
use App\Services\Work\Points;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * «شكر زميل» (12b): يختار الموظف زميلاً أو أكثر وقيمة من قيم الشركة ويكتب رسالة — يُنشر في مجتمع الشركة،
 * يُبلَّغ المستلمون، وتُضاف نقاط (إن حُددت) إلى رصيدهم في متجر المكافآت. حد شهري لكل مرسل، ولا شكر للنفس.
 */
class Praise
{
    public function __construct(private Space $space) {}

    public static function config(): array
    {
        $d = Settings::DEFAULTS['praise'];
        $out = array_merge($d, array_intersect_key(Settings::get('praise') ?? [], $d));
        $out['values'] = array_values(array_filter($out['values'] ?: $d['values'], fn ($v) => ! empty($v['key']) && ! empty($v['ar'])));
        return $out;
    }

    public static function usedThisMonth(User $u): int
    {
        return PraiseRow::where('from_user_id', $u->id)->where('created_at', '>=', now()->startOfMonth())->count();
    }

    /** ما يحتاجه المنشئ في الواجهة */
    public static function client(User $u): array
    {
        $c = self::config();
        return ['enabled' => (bool) $c['enabled'] && (bool) Space::config()['enabled'], 'values' => $c['values'], 'points' => (int) $c['points'], 'max_recipients' => (int) $c['max_recipients'],
            'min_message' => (int) $c['min_message'], 'limit' => (int) $c['monthly_limit'], 'left' => max(0, (int) $c['monthly_limit'] - self::usedThisMonth($u))];
    }

    /** الأكثر تلقياً للشكر هذا الشهر (للوحة جانبية) */
    public static function top(int $n = 5): array
    {
        $counts = [];
        foreach (PraiseRow::where('created_at', '>=', now()->startOfMonth())->pluck('recipients') as $rs) foreach ((array) $rs as $id) $counts[$id] = ($counts[$id] ?? 0) + 1;
        arsort($counts);
        $ids = array_slice(array_keys($counts), 0, $n, true);
        $emps = Employee::whereIn('id', $ids)->get()->keyBy('id');
        return collect($ids)->filter(fn ($id) => isset($emps[$id]))->map(fn ($id) => ['employee_id' => $id, 'name' => ['ar' => $emps[$id]->name, 'en' => $emps[$id]->name_en ?: $emps[$id]->name],
            'title' => ['ar' => $emps[$id]->title_ar, 'en' => $emps[$id]->title_en], 'gender' => $emps[$id]->gender, 'count' => $counts[$id]])->values()->all();
    }

    public function give(User $u, array $in): SpacePost
    {
        $cfg = self::config();
        if (! Space::config()['enabled'] || ! $cfg['enabled']) throw new ApiException(L::t('الشكر غير مفعّل في شركتك.', 'Praise is turned off for your company.'), 'praise_off', 409);
        if (! Perm::can($u, 'space', 'create')) abort(403);
        $max = (int) $cfg['max_recipients']; $min = (int) $cfg['min_message'];
        $d = validator($in, ['recipients' => ['required', 'array', 'min:1', 'max:'.$max], 'recipients.*' => ['integer'], 'value' => ['required', 'in:'.implode(',', array_column($cfg['values'], 'key'))],
            'message' => ['required', 'string', 'min:'.$min, 'max:1000']],
            ['recipients.max' => L::t("حتى {$max} زملاء في الشكر الواحد.", "Up to {$max} colleagues per praise."), 'value.in' => L::t('اختر قيمة من قيم الشركة.', 'Choose one of the company values.')],
            ['recipients' => L::t('الزملاء', 'Colleagues'), 'value' => L::t('القيمة', 'Value'), 'message' => L::t('رسالة الشكر', 'Message')])->validate();
        $ids = array_values(array_unique(array_map('intval', $d['recipients'])));
        if ($u->employee_id && in_array($u->employee_id, $ids, true)) throw ApiException::field('recipients', L::t('لا يمكنك شكر نفسك.', 'You cannot praise yourself.'), 'self');
        $emps = Employee::current()->whereIn('id', $ids)->get();
        if ($emps->count() !== count($ids)) throw ApiException::field('recipients', L::t('اختر زملاء حاليين من شركتك.', 'Choose current colleagues from your company.'), 'unknown');
        if (self::usedThisMonth($u) >= (int) $cfg['monthly_limit']) throw new ApiException(L::t("بلغت حد الشكر لهذا الشهر ({$cfg['monthly_limit']}).", "You reached this month's praise limit ({$cfg['monthly_limit']})."), 'praise_limit', 409);

        $this->space->ensureDefaults();
        $community = Community::where('kind', 'company')->firstOrFail();
        $value = collect($cfg['values'])->firstWhere('key', $d['value']);
        $users = User::where('company_id', $u->company_id)->where('active', true)->whereIn('employee_id', $ids)->pluck('id', 'employee_id');
        $points = (int) $cfg['points'];
        $people = $emps->map(fn ($e) => ['employee_id' => $e->id, 'user_id' => $users[$e->id] ?? null, 'name' => ['ar' => $e->name, 'en' => $e->name_en ?: $e->name],
            'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'gender' => $e->gender])->values()->all();

        [$post, $praise] = DB::transaction(function () use ($u, $d, $community, $value, $people, $users, $points, $ids) {
            $post = SpacePost::create(['community_id' => $community->id, 'user_id' => $u->id, 'kind' => 'praise', 'body' => trim($d['message']),
                'mentions' => $users->values()->all() ?: null, 'meta' => ['value' => $value, 'recipients' => $people, 'points' => $points]]);
            $praise = PraiseRow::create(['from_user_id' => $u->id, 'value' => $value['key'], 'message' => trim($d['message']), 'recipients' => $ids, 'points' => $points, 'space_post_id' => $post->id]);
            return [$post, $praise];
        });
        foreach ($emps as $e) {
            $got = Points::award($e, $points, 'praise', $praise->id, "شكر من زميل: {$value['ar']}", "Praise from a colleague: {$value['en']}", $u->id, false, false);
            if ($uid = $users[$e->id] ?? null) Notify::user($uid, $u->displayName('ar')." شكرك على «{$value['ar']}» في الساحة".($got ? " وأُضيفت {$got} نقطة إلى رصيدك" : ''),
                $u->displayName('en')." praised you for «{$value['en']}»".($got ? " — {$got} points added" : ''), 'space:'.$post->id);
        }
        return $post;
    }
}
