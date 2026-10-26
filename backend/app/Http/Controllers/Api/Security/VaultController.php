<?php

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\SalaryGrant;
use App\Services\Security\SalaryGrants;
use App\Services\Security\SalaryVault;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** حماية الرواتب بخطوتين (SRS §4.4) — لكل حساب بجلسة (HR والموظف)، وإعدادات الشركة بصلاحية settings,edit */
class VaultController extends Controller
{
    public function __construct(private SalaryVault $vault) {}

    public function show(Request $r): JsonResponse { return response()->json(['data' => $this->vault->state($r->user())]); }

    public function lock(Request $r): JsonResponse { $this->vault->lock($r->user()); return $this->show($r); }

    public function enrol(Request $r): JsonResponse { return response()->json(['data' => $this->vault->enrol($r->user())]); }

    public function confirm(Request $r): JsonResponse { return response()->json(['data' => $this->vault->confirm($r->user(), $this->code($r))]); }

    public function verifyApp(Request $r): JsonResponse { return response()->json(['data' => $this->vault->verifyApp($r->user(), $this->code($r))]); }

    public function verifyBackup(Request $r): JsonResponse
    {
        $c = $r->validate(['code' => ['required', 'string', 'max:20']])['code'];
        return response()->json(['data' => $this->vault->verifyBackup($r->user(), $c)]);
    }

    public function sendOtp(Request $r): JsonResponse { return response()->json(['data' => $this->vault->sendOtp($r->user())]); }

    public function verifyOtp(Request $r): JsonResponse { return response()->json(['data' => $this->vault->verifyOtp($r->user(), $this->code($r))]); }

    public function passkeyOptions(Request $r): JsonResponse { return response()->json(['data' => $this->vault->passkeyOptions($r->user())]); }

    public function passkeyRegister(Request $r): JsonResponse
    {
        $d = $r->validate(['id' => ['required', 'string', 'max:500'], 'client_data' => ['required', 'string', 'max:5000'], 'authenticator_data' => ['required', 'string', 'max:5000'],
            'public_key' => ['required', 'string', 'max:3000'], 'alg' => ['required', 'integer'], 'label' => ['nullable', 'string', 'max:80']]);
        return response()->json(['data' => $this->vault->registerPasskey($r->user(), $d)]);
    }

    public function passkeyVerify(Request $r): JsonResponse
    {
        $d = $r->validate(['id' => ['required', 'string', 'max:500'], 'client_data' => ['required', 'string', 'max:5000'], 'authenticator_data' => ['required', 'string', 'max:5000'], 'signature' => ['required', 'string', 'max:3000']]);
        return response()->json(['data' => $this->vault->verifyPasskey($r->user(), $d)]);
    }

    public function removePasskey(Request $r, int $id): JsonResponse { return response()->json(['data' => $this->vault->removePasskey($r->user(), $id)]); }

    public function reset(Request $r): JsonResponse
    {
        $p = $r->validate(['password' => ['required', 'string', 'max:200']])['password'];
        return response()->json(['data' => $this->vault->reset($r->user(), $p)]);
    }

    // ——— موافقة من الموبايل ———

    public function linkStart(Request $r): JsonResponse { return response()->json(['data' => $this->vault->linkStart($r->user())]); }

    public function linkStatus(Request $r, string $token): JsonResponse { return response()->json(['data' => $this->vault->linkStatus($r->user(), $token)]); }

    public function linkInfo(Request $r, string $token): JsonResponse { return response()->json(['data' => $this->vault->linkInfo($r->user(), $token)]); }

    public function linkDecide(Request $r, string $token): JsonResponse
    {
        $ok = $r->validate(['approve' => ['required', 'boolean']])['approve'];
        return response()->json(['data' => $this->vault->linkDecide($r->user(), $token, (bool) $ok)]);
    }

    // ——— إذن الاطلاع على رواتب الآخرين ———

    public function grants(Request $r): JsonResponse
    {
        $u = $r->user();
        $rows = SalaryGrant::with(SalaryGrants::WITH)->where('user_id', $u->id)->latest('id')->limit(10)->get();
        return response()->json(['data' => $rows->map(fn ($g) => SalaryGrants::row($g, $u))->values(), 'needed' => SalaryVault::needsGrant($u), 'hours' => (int) Settings::vault()['grant_hours'],
            'departments' => Department::orderBy('id')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en])->values()]);
    }

    public function requestGrant(Request $r, SalaryGrants $g): JsonResponse
    {
        $d = $r->validate(['scope' => ['required', 'in:all,department'], 'department_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $g->request($r->user(), $d);
        return $this->grants($r);
    }

    public function cancelGrant(Request $r, SalaryGrants $g, int $id): JsonResponse
    {
        $g->cancel(SalaryGrant::where('user_id', $r->user()->id)->findOrFail($id), $r->user());
        return $this->grants($r);
    }

    /** لصفحة «حماية الرواتب»: الأذونات القائمة والأخيرة */
    public function allGrants(Request $r): JsonResponse
    {
        $rows = SalaryGrant::with(SalaryGrants::WITH)->latest('id')->limit(60)->get();
        return response()->json(['data' => $rows->map(fn ($g) => SalaryGrants::row($g, $r->user()))->values(), 'can_revoke' => SalaryGrants::canRevoke($r->user())]);
    }

    public function revokeGrant(Request $r, SalaryGrants $g, int $id): JsonResponse
    {
        $g->revoke(SalaryGrant::findOrFail($id), $r->user(), $r->input('note'));
        return $this->allGrants($r);
    }

    private function code(Request $r): string { return $r->validate(['code' => ['required', 'string', 'max:12']])['code']; }

    // ——— إعدادات الشركة ———

    public function settings(): JsonResponse
    {
        return response()->json(['data' => Settings::vault(), 'defaults' => Settings::DEFAULTS['salary_vault'],
            'roles' => \App\Models\Role::whereIn('key', \App\Support\Perm::ROLES)->get()->map(fn ($r) => ['key' => $r->key, 'ar' => $r->name_ar, 'en' => $r->name_en])->values()]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate([
            'unlock_minutes' => ['required', 'integer', 'between:1,60'], 'max_minutes' => ['required', 'integer', 'between:1,720', 'gte:unlock_minutes'], 'max_attempts' => ['required', 'integer', 'between:3,10'], 'lockout_seconds' => ['required', 'integer', 'between:30,3600'],
            'otp_minutes' => ['required', 'integer', 'between:1,10'], 'link_seconds' => ['required', 'integer', 'between:30,600'], 'backup_count' => ['required', 'integer', 'between:4,16'], 'audit_keep' => ['required', 'integer', 'between:20,1000'],
            'methods' => ['required', 'array'], 'methods.link' => ['required', 'boolean'], 'methods.phone' => ['required', 'boolean'], 'methods.passkey' => ['required', 'boolean'], 'methods.app' => ['required', 'boolean'],
            'grant_required' => ['required', 'boolean'], 'grant_hours' => ['required', 'integer', 'between:1,720'], 'grant_exempt_roles' => ['present', 'array'], 'grant_exempt_roles.*' => ['string', 'exists:roles,key'],
        ], [], ['max_minutes' => L::t('الحد الأقصى للفتح', 'Maximum unlock'), 'link_seconds' => L::t('صلاحية طلب الموبايل', 'Phone request validity'), 'grant_hours' => L::t('مدة الإذن', 'Access duration'),
            'unlock_minutes' => L::t('مدة الفتح', 'Unlock window'), 'max_attempts' => L::t('المحاولات', 'Attempts'), 'lockout_seconds' => L::t('مدة الإيقاف', 'Lockout'),
            'otp_minutes' => L::t('صلاحية الرمز', 'Code validity'), 'backup_count' => L::t('رموز الطوارئ', 'Backup codes'), 'audit_keep' => L::t('سجل المحاولات', 'Attempt log')]);
        if (! in_array(true, $d['methods'], true)) throw \Illuminate\Validation\ValidationException::withMessages(['methods' => [L::t('فعّل طريقة تحقق واحدة على الأقل.', 'Turn on at least one verification method.')]]);
        $before = Settings::vault();
        $new = [];
        foreach (['unlock_minutes', 'max_minutes', 'max_attempts', 'lockout_seconds', 'otp_minutes', 'link_seconds', 'backup_count', 'audit_keep', 'grant_hours'] as $k) $new[$k] = (int) $d[$k];
        $new['methods'] = ['link' => (bool) $d['methods']['link'], 'passkey' => (bool) $d['methods']['passkey'], 'phone' => (bool) $d['methods']['phone'], 'app' => (bool) $d['methods']['app']];
        $new['grant_required'] = (bool) $d['grant_required'];
        $new['grant_exempt_roles'] = array_values(array_unique($d['grant_exempt_roles']));
        Settings::put('salary_vault', $new);
        Audit::record('settings.salary_vault', null, $before, $new);
        return $this->settings();
    }
}
