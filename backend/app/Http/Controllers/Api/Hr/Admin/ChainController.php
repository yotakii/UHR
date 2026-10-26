<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalChain;
use App\Models\Role;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * M16 «سلاسل الاعتماد»: لكل نوع طلب مستوياته بالترتيب — الدور (أو المدير المباشر)، صاحب محدد اختياري، ونائب اختياري يعتمد بدلاً منه.
 * التغيير يسري على الطلبات الجديدة فقط؛ القائمة تبقى بمسارها.
 */
class ChainController extends Controller
{
    /** الأنواع المعروفة بالترتيب (المفتاح ← الاسم) */
    public const TYPES = [
        'leave' => ['الإجازات', 'Leave'], 'justification' => ['تبرير الغياب والتأخير', 'Justifications'], 'overtime' => ['التكليف بالإضافي F-04', 'Overtime assignment F-04'],
        'service' => ['طلبات الخدمات والشهادات', 'Services & certificates'], 'advance' => ['السلفة', 'Salary advance'], 'internal' => ['الترشيح الداخلي F-27', 'Internal application F-27'],
        'resignation' => ['الاستقالة F-02', 'Resignation F-02'], 'termination' => ['إنهاء العقد F-03', 'Termination F-03'], 'penalty' => ['الجزاءات الأشد', 'Higher penalties'],
        'appointment' => ['قرار التعيين F-31', 'Appointment decision F-31'], 'salary_access' => ['إذن الاطلاع على الرواتب', 'Salary data access'],
    ];

    /** الأدوار المسموحة كمستوى («manager» = المدير المباشر للموظف) */
    public const LEVEL_ROLES = ['manager', 'hr_director', 'finance', 'executive', 'ceo', 'admin'];

    public function index(): JsonResponse
    {
        $chains = ApprovalChain::all()->keyBy('type');
        $users = User::with(['employee', 'roles'])->where('company_id', \App\Support\Tenant::id())->where('active', true)->orderBy('id')->get();
        $name = fn (?int $id) => ($u = $users->firstWhere('id', $id)) ? ['ar' => $u->displayName('ar'), 'en' => $u->displayName('en')] : null;
        $roles = Role::whereIn('key', self::LEVEL_ROLES)->get()->keyBy('key');
        return response()->json([
            'data' => collect(self::TYPES)->map(fn ($t, $k) => ['type' => $k, 'ar' => $t[0], 'en' => $t[1], 'manager_allowed' => $k !== 'appointment' && $k !== 'salary_access',
                'levels' => array_map(fn ($lv) => $lv + ['user' => $name($lv['user_id'] ?? null), 'deputy' => $name($lv['deputy_id'] ?? null)], $chains->get($k)?->levels ?? Workflow::chain($k))])->values(),
            'roles' => collect(self::LEVEL_ROLES)->map(fn ($r) => ['key' => $r, 'ar' => $r === 'manager' ? 'المدير المباشر' : ($roles[$r]->name_ar ?? $r), 'en' => $r === 'manager' ? 'Direct manager' : ($roles[$r]->name_en ?? $r)])->values(),
            'users' => $users->map(fn ($u) => ['id' => $u->id, 'ar' => $u->displayName('ar'), 'en' => $u->displayName('en'), 'email' => $u->email, 'roles' => $u->roles->pluck('key')])->values(),
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $d = $request->validate([
            'levels' => ['required', 'array', 'min:1', 'max:6'], 'levels.*.role_key' => ['required', 'string', 'in:'.implode(',', self::LEVEL_ROLES)],
            'levels.*.role_ar' => ['required', 'string', 'max:60'], 'levels.*.role_en' => ['required', 'string', 'max:60'],
            'levels.*.user_id' => ['nullable', 'integer', 'exists:users,id'], 'levels.*.deputy_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [], ['levels.*.role_ar' => L::t('اسم المستوى', 'Level name'), 'levels.*.role_en' => L::t('اسم المستوى', 'Level name')]);
        $ids = User::where('company_id', \App\Support\Tenant::id())->pluck('id')->all();
        foreach ($d['levels'] as $i => $lv) {
            if ($lv['role_key'] === 'manager' && in_array($type, ['appointment', 'salary_access'], true)) throw ValidationException::withMessages(["levels.$i.role_key" => [L::t('هذا المسار لا يرتبط بموظف — اختر دوراً.', 'This path has no employee — choose a role.')]]);
            foreach (['user_id', 'deputy_id'] as $f) if (! empty($lv[$f]) && ! in_array((int) $lv[$f], $ids, true)) throw ValidationException::withMessages(["levels.$i.$f" => [L::t('المستخدم ليس من الشركة.', 'The user is not in this company.')]]);
            if (! empty($lv['deputy_id']) && (int) $lv['deputy_id'] === (int) ($lv['user_id'] ?? 0)) throw ValidationException::withMessages(["levels.$i.deputy_id" => [L::t('النائب يجب أن يختلف عن صاحب المستوى.', 'The deputy must differ from the level owner.')]]);
        }
        $levels = array_map(fn ($lv) => ['role_key' => $lv['role_key'], 'role_ar' => trim($lv['role_ar']), 'role_en' => trim($lv['role_en']),
            'user_id' => $lv['role_key'] === 'manager' ? null : ($lv['user_id'] ?? null), 'deputy_id' => $lv['deputy_id'] ?? null], array_values($d['levels']));
        $c = ApprovalChain::firstOrNew(['type' => $type]);
        $before = $c->levels;
        $c->levels = $levels;
        $c->save();
        Audit::record('settings.chain', $c, ['levels' => $before], ['levels' => $levels]);
        return $this->index();
    }
}
