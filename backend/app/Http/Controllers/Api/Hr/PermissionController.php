<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * لوحة الصلاحيات (M16 — SRS §10.2): شبكة الأدوار × الوحدات × الإجراءات مع النطاق.
 * التعديل يُطبَّق فوراً (تُمسح ذاكرة المصفوفة)، ويُسجَّل في التدقيق بالخلايا المتغيرة قبل/بعد.
 */
class PermissionController extends Controller
{
    public function show(): JsonResponse
    {
        $roles = Role::whereIn('key', Perm::ROLES)->get()->keyBy('key');
        $counts = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->join('users', 'users.id', '=', 'role_user.user_id')
            ->where('users.company_id', Tenant::id())->groupBy('roles.key')->selectRaw('roles.key, count(*) as n')->pluck('n', 'key');
        return response()->json([
            'actions' => collect(Perm::ACTIONS)->map(fn ($a, $k) => ['key' => $k, 'letter' => $a[0], 'ar' => $a[1], 'en' => $a[2]])->values(),
            'scopes' => collect(Perm::SCOPES)->map(fn ($s, $k) => ['key' => $k, 'ar' => $s[0], 'en' => $s[1]])->values(),
            'modules' => collect(Perm::MODULES)->map(fn ($m, $k) => ['key' => $k, 'ar' => $m[0], 'en' => $m[1]])->values(),
            'roles' => collect(Perm::ROLES)->map(fn ($k) => ['key' => $k, 'ar' => $roles[$k]->name_ar ?? $k, 'en' => $roles[$k]->name_en ?? $k, 'users' => (int) ($counts[$k] ?? 0)])->values(),
            'matrix' => Perm::matrix(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'matrix' => ['required', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*.actions' => ['present', 'array'],
            'matrix.*.*.actions.*' => [Rule::in(array_keys(Perm::ACTIONS))],
            'matrix.*.*.scope' => ['required', Rule::in(array_keys(Perm::SCOPES))],
        ]);
        $before = Perm::matrix();
        $changes = [];
        foreach ($data['matrix'] as $role => $modules) {
            if (! in_array($role, Perm::ROLES, true)) throw ApiException::field('matrix', L::t("دور غير معروف: {$role}", "Unknown role: {$role}"), 'unknown_role');
            foreach ($modules as $module => $cell) {
                if (! isset(Perm::MODULES[$module])) throw ApiException::field('matrix', L::t("وحدة غير معروفة: {$module}", "Unknown module: {$module}"), 'unknown_module');
                $cell = ['actions' => Perm::order($cell['actions']), 'scope' => $cell['scope']];
                // «عرض» شرط لأي إجراء آخر في الوحدة
                if ($cell['actions'] && ! in_array('view', $cell['actions'], true)) array_unshift($cell['actions'], 'view');
                if ($cell != $before[$role][$module]) $changes["{$role}.{$module}"] = [$before[$role][$module], $cell];
            }
        }
        $this->guardSelfLockout($request->user(), $data['matrix']);

        DB::transaction(function () use ($changes) {
            foreach ($changes as $key => [, $cell]) {
                [$role, $module] = explode('.', $key);
                RolePermission::updateOrCreate(['role' => $role, 'module' => $module], $cell);
            }
        });
        Perm::forget();
        if ($changes) Audit::record('permissions.updated', null, collect($changes)->map(fn ($c) => self::label($c[0]))->all(), collect($changes)->map(fn ($c) => self::label($c[1]))->all());
        return response()->json(['matrix' => Perm::matrix(), 'changed' => count($changes)]);
    }

    /** لا يسحب المستخدم من نفسه إدارة الصلاحيات (وإلا تُقفل اللوحة على الشركة) */
    private function guardSelfLockout(User $user, array $matrix): void
    {
        $keeps = $user->roles->pluck('key')->contains(function ($role) use ($matrix) {
            $cell = $matrix[$role]['users'] ?? Perm::matrix()[$role]['users'] ?? null;
            return $cell && in_array('edit', $cell['actions'], true);
        });
        if (! $keeps) throw new ApiException(L::t('لا يمكنك سحب صلاحية تعديل الصلاحيات من دورك — ستفقد الوصول لهذه اللوحة.', 'You cannot remove permission editing from your own role — you would lose access to this panel.'), 'self_lockout', 422);
    }

    private static function label(array $cell): string
    {
        return implode('', array_map(fn ($a) => Perm::ACTIONS[$a][0], $cell['actions'])).' · '.$cell['scope'];
    }
}
