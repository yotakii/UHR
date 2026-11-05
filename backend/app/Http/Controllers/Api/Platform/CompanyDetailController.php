<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\Accounts\Invites;
use App\Support\Audit;
use App\Support\HostCompany;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تفاصيل الشركة في لوحة المنصة (ملاحظتا 47/48): بياناتها وبوابتها واستخدامها للمنصة، وحساباتها وحالة اعتماد كل حساب.
 * لا تُعرض كلمة مرور أبداً — الحالة فقط (مفعّل، بانتظار التفعيل، مقفل، موقوف، كلمة مؤقتة، تحقق ثنائي)،
 * والإجراءات الآمنة: إرسال رابط تعيين كلمة مرور بالبريد، فك القفل، إيقاف/تفعيل — وكلها تُسجَّل.
 */
class CompanyDetailController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $c = Company::findOrFail($id);
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $month = now()->startOfMonth();
        $count = fn (string $table, ?\Closure $w = null) => (int) DB::table($table)->where('company_id', $c->id)->when($w, $w)->count();
        $users = User::with(['roles', 'employee', 'mfa'])->withCount('passkeys')->where('company_id', $c->id)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->whereRaw('lower(email) like ?', ["%{$q}%"])->orWhereRaw('lower(coalesce(name, \'\')) like ?', ["%{$q}%"])
                ->orWhereHas('employee', fn ($e) => $e->whereRaw('lower(name) like ?', ["%{$q}%"])->orWhereRaw('lower(name_en) like ?', ["%{$q}%"])->orWhere('code', 'ilike', "%{$q}%"))))
            ->orderByDesc('active')->orderBy('email')->limit(500)->get();
        $all = User::where('company_id', $c->id);
        $usage = [
            'users' => ['total' => (clone $all)->count(), 'active' => (clone $all)->where('active', true)->count(), 'inactive' => (clone $all)->where('active', false)->count(),
                'locked' => (clone $all)->where('locked_until', '>', now())->count(), 'pending' => (clone $all)->whereNull('activated_at')->whereNotNull('invited_at')->count(),
                'signed_in_30d' => (clone $all)->where('last_login_at', '>=', now()->subDays(30))->count(), 'last_login_at' => (clone $all)->max('last_login_at')],
            'employees' => Employee::allCompanies()->where('company_id', $c->id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'departments' => $count('departments'),
            'jobs' => ['total' => JobOpening::allCompanies()->where('company_id', $c->id)->count(), 'open' => JobOpening::allCompanies()->where('company_id', $c->id)->where('status', 'open')->count()],
            'applications' => ['total' => Candidate::allCompanies()->where('company_id', $c->id)->count(), 'month' => Candidate::allCompanies()->where('company_id', $c->id)->where('created_at', '>=', $month)->count()],
            'attendance_30d' => $count('attendance_days', fn ($w) => $w->where('date', '>=', now()->subDays(30)->toDateString())),
            'requests_30d' => $count('leave_requests', fn ($w) => $w->where('created_at', '>=', now()->subDays(30))) + $count('service_requests', fn ($w) => $w->where('created_at', '>=', now()->subDays(30))),
            'files' => ['count' => $count('files'), 'bytes' => (int) DB::table('files')->where('company_id', $c->id)->sum('size')],
            'mail_month' => ['sent' => $count('mail_logs', fn ($w) => $w->where('created_at', '>=', $month)->where('status', 'sent')), 'failed' => $count('mail_logs', fn ($w) => $w->where('created_at', '>=', $month)->where('status', 'failed'))],
            'cv_readings_month' => (int) DB::table('candidate_reviews')->join('candidates', 'candidates.id', '=', 'candidate_reviews.candidate_id')->where('candidates.company_id', $c->id)->where('candidate_reviews.created_at', '>=', $month)->count(),
            'audit_30d' => AuditLog::where('company_id', $c->id)->where('at', '>=', now()->subDays(30))->count(),
            'last_activity_at' => AuditLog::where('company_id', $c->id)->max('at'),
        ];
        Audit::record('platform.company_viewed', $c);
        return response()->json(['data' => [
            'id' => $c->id, 'slug' => $c->slug, 'name' => ['ar' => $c->name_ar, 'en' => $c->name_en], 'status' => $c->status, 'is_platform' => $c->is_platform,
            'website' => $c->website, 'domain' => $c->domain, 'portal_urls' => HostCompany::urls($c), 'created_at' => $c->created_at?->toIso8601String(),
            'usage' => $usage,
            'accounts' => $users->map(fn (User $u) => self::account($u))->values(),
        ]]);
    }

    /** حالة الاعتماد بلا كلمات مرور */
    public static function account(User $u): array
    {
        $state = ! $u->active ? 'inactive' : ($u->locked_until?->isFuture() ? 'locked' : (! $u->isActivated() ? (Invites::state($u) === 'expired' ? 'expired' : 'pending') : 'active'));
        return ['id' => $u->id, 'email' => $u->email, 'name' => ['ar' => $u->displayName('ar'), 'en' => $u->displayName('en')], 'code' => $u->employee?->code,
            'roles' => $u->roles->map(fn ($r) => ['key' => $r->key, 'ar' => $r->name_ar, 'en' => $r->name_en])->values(), 'state' => $state,
            'temp_password' => (bool) $u->must_change_password, 'mfa' => (bool) $u->mfa?->enrolled_at, 'passkeys' => (int) ($u->passkeys_count ?? 0),
            'failed_logins' => (int) $u->failed_logins, 'last_login_at' => $u->last_login_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String()];
    }

    /** reset-password: رابط تعيين بالبريد لصاحب الحساب (لا يُعرض الرابط هنا) · unlock · deactivate · activate */
    public function userAction(Request $request, int $id, int $userId, string $action): JsonResponse
    {
        $c = Company::findOrFail($id);
        $u = User::with('employee')->where('company_id', $c->id)->findOrFail($userId);
        switch ($action) {
            case 'reset-password':
                if (! $u->active) throw new ApiException(L::t('فعّل الحساب أولاً.', 'Activate the account first.'), 'inactive', 409);
                $u->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
                Tenant::run($c->id, fn () => app(Invites::class)->issue($u, $u->isActivated() ? 'reset' : ($u->employee_id ? 'employee' : 'invite'), $u->employee?->code));
                break;
            case 'unlock': $u->forceFill(['failed_logins' => 0, 'locked_until' => null])->save(); break;
            case 'deactivate':
            case 'activate': $u->update(['active' => $action === 'activate']); break;
            default: abort(404);
        }
        Audit::record('platform.user_'.str_replace('-', '_', $action), $u, null, ['company' => $c->slug]);
        return response()->json(['data' => self::account($u->fresh(['roles', 'employee', 'mfa'])->loadCount('passkeys')), 'sent' => $action === 'reset-password']);
    }
}
