<?php

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
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

    private function code(Request $r): string { return $r->validate(['code' => ['required', 'string', 'max:12']])['code']; }

    // ——— إعدادات الشركة ———

    public function settings(): JsonResponse { return response()->json(['data' => Settings::vault(), 'defaults' => Settings::DEFAULTS['salary_vault']]); }

    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate([
            'unlock_minutes' => ['required', 'integer', 'between:1,60'], 'max_attempts' => ['required', 'integer', 'between:3,10'], 'lockout_seconds' => ['required', 'integer', 'between:30,3600'],
            'otp_minutes' => ['required', 'integer', 'between:1,10'], 'backup_count' => ['required', 'integer', 'between:4,16'], 'audit_keep' => ['required', 'integer', 'between:20,1000'],
            'methods' => ['required', 'array'], 'methods.phone' => ['required', 'boolean'], 'methods.passkey' => ['required', 'boolean'], 'methods.app' => ['required', 'boolean'],
        ], [], ['unlock_minutes' => L::t('مدة الفتح', 'Unlock window'), 'max_attempts' => L::t('المحاولات', 'Attempts'), 'lockout_seconds' => L::t('مدة الإيقاف', 'Lockout'),
            'otp_minutes' => L::t('صلاحية الرمز', 'Code validity'), 'backup_count' => L::t('رموز الطوارئ', 'Backup codes'), 'audit_keep' => L::t('سجل المحاولات', 'Attempt log')]);
        if (! in_array(true, $d['methods'], true)) throw \Illuminate\Validation\ValidationException::withMessages(['methods' => [L::t('فعّل طريقة تحقق واحدة على الأقل.', 'Turn on at least one verification method.')]]);
        $before = Settings::vault();
        $new = array_map(fn ($v) => is_array($v) ? array_map('boolval', $v) : (int) $v, $d);
        $new['methods'] = ['phone' => (bool) $d['methods']['phone'], 'passkey' => (bool) $d['methods']['passkey'], 'app' => (bool) $d['methods']['app']];
        Settings::put('salary_vault', $new);
        Audit::record('settings.salary_vault', null, $before, $new);
        return $this->settings();
    }
}
