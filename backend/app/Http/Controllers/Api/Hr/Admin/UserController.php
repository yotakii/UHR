<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MfaFactor;
use App\Models\Passkey;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * M16 «المستخدمون والأدوار»: حسابات الشركة، أدوارها (RBAC)، إنشاء حساب (لموظف أو بلا ملف)، إعادة تعيين كلمة المرور (مؤقتة + بريد)،
 * إيقاف/تفعيل، فك قفل محاولات الدخول، إلغاء ربط التحقق بخطوتين. لا يوقف المدير نفسه ولا يسحب منها دور المدير (self_lockout).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', '')); $role = $request->query('role'); $status = $request->query('status');
        $rows = User::with(['employee.department', 'roles', 'mfa'])->withCount('passkeys')->where('company_id', Tenant::id())
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('email', 'ilike', "%$q%")->orWhere('name', 'ilike', "%$q%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%$q%")->orWhere('name_en', 'ilike', "%$q%")->orWhere('code', 'ilike', "%$q%"))))
            ->when($role, fn ($w) => $w->whereHas('roles', fn ($r) => $r->where('key', $role)))
            ->when($status === 'active', fn ($w) => $w->where('active', true))->when($status === 'inactive', fn ($w) => $w->where('active', false))
            ->when($status === 'locked', fn ($w) => $w->where('locked_until', '>', now()))
            ->orderBy('id')->limit(500)->get();
        return response()->json(['data' => $rows->map(fn (User $u) => $this->row($u))->values(),
            'roles' => Role::whereIn('key', Perm::ROLES)->get()->sortBy(fn ($r) => array_search($r->key, Perm::ROLES, true))->map(fn ($r) => ['key' => $r->key, 'ar' => $r->name_ar, 'en' => $r->name_en])->values(),
            'employees' => Employee::current()->whereDoesntHave('user')->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'email'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'email' => $e->email]),
            'can' => ['create' => Perm::can($request->user(), 'users', 'create'), 'edit' => Perm::can($request->user(), 'users', 'edit')]]);
    }

    private function row(User $u): array
    {
        $e = $u->employee;
        return ['id' => $u->id, 'email' => $u->email, 'name' => ['ar' => $u->displayName('ar'), 'en' => $u->displayName('en')], 'active' => $u->active,
            'employee' => $e ? ['id' => $e->id, 'code' => $e->code, 'status' => $e->status, 'dept' => $e->department ? ['ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null] : null,
            'roles' => $u->roles->pluck('key')->values(), 'last_login_at' => $u->last_login_at?->toIso8601String(), 'locked' => (bool) ($u->locked_until?->isFuture()),
            'must_change_password' => (bool) $u->must_change_password, 'mfa' => (bool) $u->mfa?->enrolled_at, 'passkeys' => (int) $u->passkeys_count];
    }

    private function roles(array $keys, ?User $target, User $by): array
    {
        $keys = array_values(array_unique(array_intersect($keys, Perm::ROLES)));
        if ($target && $target->id === $by->id && $target->hasRole('admin') && ! in_array('admin', $keys, true))
            throw new ApiException(L::t('لا يمكنك سحب دور مدير النظام من حسابك.', 'You cannot remove the administrator role from your own account.'), 'self_lockout', 409);
        return Role::whereIn('key', $keys)->pluck('id')->all();
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', Tenant::id())],
            'email' => ['required_without:employee_id', 'nullable', 'email', 'max:190'], 'name' => ['required_without:employee_id', 'nullable', 'string', 'max:120'], 'name_en' => ['nullable', 'string', 'max:120'],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::in(Perm::ROLES)],
        ], [], ['email' => L::t('البريد', 'Email'), 'name' => L::t('الاسم', 'Name'), 'roles' => L::t('الأدوار', 'Roles')]);
        $by = $request->user();
        $e = ! empty($d['employee_id']) ? Employee::findOrFail($d['employee_id']) : null;
        if ($e && User::where('employee_id', $e->id)->exists()) throw new ApiException(L::t('لهذا الموظف حساب مسبقاً.', 'This employee already has an account.'), 'has_account', 409);
        $email = strtolower(trim($e?->email ?: $d['email']));
        if (User::where('email', $email)->exists()) throw ApiException::field('email', L::t('البريد مستخدم لحساب آخر.', 'This email belongs to another account.'), 'email_taken');
        $temp = Str::password(12, symbols: false);
        $u = DB::transaction(function () use ($d, $e, $email, $temp, $by) {
            $u = User::create(['email' => $email, 'password' => $temp, 'name' => $e ? null : trim($d['name']), 'name_en' => $e ? null : (trim((string) ($d['name_en'] ?? '')) ?: null),
                'employee_id' => $e?->id, 'company_id' => Tenant::id(), 'must_change_password' => true, 'active' => true]);
            $keys = $d['roles']; if ($e) $keys[] = 'employee';
            $u->roles()->sync($this->roles($keys, null, $by));
            return $u;
        });
        $co = Tenant::company();
        $u->notify((new AccountNotice('created', $co->name_ar, $co->name_en, $temp))->afterCommit());
        Audit::record('user.created', $u, null, ['email' => $email, 'roles' => $u->roles()->pluck('key')]);
        return response()->json(['data' => $this->row($u->load(['employee.department', 'roles', 'mfa'])->loadCount('passkeys')), 'temporary_password' => $temp], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $u = User::with('roles')->where('company_id', Tenant::id())->findOrFail($id);
        $d = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::in(Perm::ROLES)], 'name' => ['nullable', 'string', 'max:120'], 'name_en' => ['nullable', 'string', 'max:120']]);
        $before = $u->roles->pluck('key')->all();
        $keys = $d['roles'];
        if ($u->employee_id && ! in_array('employee', $keys, true)) $keys[] = 'employee';   // صاحب ملف موظف يبقى موظفاً
        $u->roles()->sync($this->roles($keys, $u, $request->user()));
        if (! $u->employee_id && isset($d['name'])) $u->update(['name' => trim($d['name']), 'name_en' => trim((string) ($d['name_en'] ?? '')) ?: null]);
        Audit::record('user.roles', $u, ['roles' => $before], ['roles' => $u->roles()->pluck('key')->all()]);
        return $this->one($u);
    }

    public function action(Request $request, int $id, string $action): JsonResponse
    {
        $u = User::where('company_id', Tenant::id())->findOrFail($id);
        $by = $request->user();
        $out = [];
        switch ($action) {
            case 'reset-password':
                $temp = Str::password(12, symbols: false);
                $u->update(['password' => $temp, 'must_change_password' => true, 'failed_logins' => 0, 'locked_until' => null]);
                $co = Tenant::company();
                $u->notify(new AccountNotice('reset', $co->name_ar, $co->name_en, $temp));
                Audit::record('user.password_reset', $u);
                $out['temporary_password'] = $temp;
                break;
            case 'deactivate':
            case 'activate':
                if ($u->id === $by->id) throw new ApiException(L::t('لا يمكنك إيقاف حسابك.', 'You cannot deactivate your own account.'), 'self_lockout', 409);
                $u->update(['active' => $action === 'activate']);
                Audit::record('user.'.$action.'d', $u);
                break;
            case 'unlock':
                $u->update(['failed_logins' => 0, 'locked_until' => null]);
                Audit::record('user.unlocked', $u);
                break;
            case 'reset-mfa':
                MfaFactor::where('user_id', $u->id)->delete();
                Passkey::where('user_id', $u->id)->delete();
                Audit::record('mfa.reset_by_admin', $u);
                break;
            default: abort(404);
        }
        return $this->one($u, $out);
    }

    private function one(User $u, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $this->row($u->fresh(['employee.department', 'roles', 'mfa'])->loadCount('passkeys'))] + $extra);
    }
}
