<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\User;
use App\Support\Perm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الجرس (M15): آخر 12 إشعاراً + عدد غير المقروء. HR يرى إشعارات «الموارد البشرية» لشركته وإشعاراته الشخصية؛
 * الموظف والمتقدم إشعاراتهم فقط. القراءة لكل مستخدم (notice_reads) — تُعلَّم بعد فتح القائمة.
 */
class NoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unread = $this->visible($user)->whereNotExists(fn ($q) => $q->from('notice_reads')->whereColumn('notice_reads.notice_id', 'notices.id')->where('notice_reads.user_id', $user->id))->count();
        $list = $this->visible($user)->orderByDesc('at')->orderByDesc('id')->limit(12)->get();
        $read = DB::table('notice_reads')->where('user_id', $user->id)->whereIn('notice_id', $list->pluck('id'))->pluck('notice_id')->flip();
        return response()->json(['unread' => $unread, 'data' => $list->map(fn (Notice $n) => [
            'id' => $n->id, 'text' => ['ar' => $n->text_ar, 'en' => $n->text_en], 'link' => $n->link, 'at' => $n->at->toIso8601String(), 'read' => $read->has($n->id),
        ])]);
    }

    /** يعلّم كل الظاهر للمستخدم مقروءاً */
    public function read(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->visible($user)->whereNotExists(fn ($q) => $q->from('notice_reads')->whereColumn('notice_reads.notice_id', 'notices.id')->where('notice_reads.user_id', $user->id))->pluck('id');
        DB::table('notice_reads')->insertOrIgnore($ids->map(fn ($id) => ['notice_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all());
        return response()->json(['unread' => 0]);
    }

    /** وحدة كل رابط إشعار — إشعارات «الموارد البشرية» تظهر لمن يملك عرض وحدتها (مسؤول التوظيف لا يرى شؤون الموظفين) */
    private const LINK_MODULES = ['cand:' => 'candidates', 'approvals' => 'candidates', 'job:' => 'jobs', 'emp:' => 'employees', 'leaves' => 'leaves', 'referrals' => 'referrals',
        'offboarding' => 'employees', 'custody' => 'assets', 'attendance' => 'attendance', 'onboard' => 'employees', 'payroll' => 'payroll'];

    private function visible(User $user): Builder
    {
        $perms = $user->portal() === 'hr' ? Perm::for($user) : [];
        $allowed = array_keys(array_filter(self::LINK_MODULES, fn ($m) => isset($perms[$m])));
        $general = isset($perms['employees']);   // إشعار عام بلا رابط ← لمن يدير شؤون الموظفين
        return Notice::query()->where(function ($q) use ($user, $allowed, $general) {
            $q->where(fn ($w) => $w->where('audience', 'user')->where('user_id', $user->id));
            if ($allowed || $general) $q->orWhere(fn ($w) => $w->where('audience', 'hr')->where(function ($l) use ($allowed, $general) {
                foreach ($allowed as $prefix) $l->orWhere('link', 'like', $prefix.'%');
                if ($general) $l->orWhereNull('link');
            }));
        });
    }
}
