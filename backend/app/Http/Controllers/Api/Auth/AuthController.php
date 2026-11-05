<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounts\Invites;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * الدخول بجلسة (كوكي httpOnly + CSRF) — نفس الأصل عبر الـ proxy، لا توكنات في المتصفح.
 * SRS §4.1: قفل مؤقت بعد 5 محاولات خاطئة، وإلزام تغيير كلمة المرور المؤقتة.
 */
class AuthController extends Controller
{

    public function me(Request $request): JsonResponse
    {
        // GET أيضاً يضبط كوكي XSRF-TOKEN للطلبات اللاحقة
        return response()->json(['user' => $request->user()?->sessionPayload()]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']], [
            'email.required' => L::t('أدخل البريد الإلكتروني.', 'Enter your email.'), 'email.email' => L::t('صيغة البريد غير صحيحة.', 'Invalid email format.'), 'password.required' => L::t('أدخل كلمة المرور.', 'Enter your password.'),
        ]);
        $email = strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();
        $invalid = fn (array $extra = []) => response()->json(['message' => L::t('البريد أو كلمة المرور غير صحيحة. تحقق منهما وحاول مجدداً.', 'Email or password is incorrect. Check them and try again.'), 'code' => 'invalid'] + $extra, 422);

        if (! $user) {
            Hash::check($data['password'], '$2y$12$'.str_repeat('a', 53));   // نفس زمن الاستجابة لبريد غير موجود
            return $invalid();
        }
        if ($m = $user->lockedMinutes()) return $this->locked($m);

        if (! Hash::check($data['password'], $user->password)) {
            $fails = $user->failed_logins + 1;
            $pol = \App\Support\PasswordPolicy::for($user->company_id);
            if ($fails >= $pol['max_attempts']) {
                $minutes = $pol['lock_minutes'];
                $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes($minutes)])->save();
                Audit::record('auth.locked', $user, null, ['minutes' => $minutes]);
                return $this->locked($minutes);
            }
            $user->forceFill(['failed_logins' => $fails])->save();
            Audit::record('auth.failed', $user, null, ['attempt' => $fails]);
            if (! $user->activated_at && $user->invite_hash) return response()->json(['message' => L::t('لم يُفعَّل هذا الحساب بعد. افتح رابط التفعيل المرسل إلى بريدك لتختار كلمة المرور، أو اطلب رابطاً جديداً من «نسيت كلمة المرور؟».', 'This account is not activated yet. Open the activation link sent to your email to choose your password, or request a new link with "Forgot password?".'), 'code' => 'not_activated'], 422);
            return $invalid(['remaining' => $pol['max_attempts'] - $fails]);
        }

        if ($user->blocked()) {
            Audit::record('auth.blocked', $user);
            return response()->json(['message' => L::t('هذا الحساب موقوف. راجع الموارد البشرية.', 'This account is disabled. Please contact HR.'), 'code' => 'blocked'], 403);
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'activated_at' => $user->activated_at ?? now()])->save();
        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('auth.login', $user);
        return response()->json(['user' => $user->sessionPayload()]);
    }

    private function locked(int $minutes): JsonResponse
    {
        return response()->json(['message' => L::t("تم إيقاف الدخول مؤقتاً بعد عدة محاولات خاطئة. حاول بعد {$minutes} دقيقة، أو أعد تعيين كلمة المرور.", "Sign-in is paused after several wrong attempts. Try again in {$minutes} min, or reset your password."), 'code' => 'locked', 'minutes' => $minutes], 423);
    }

    /** صفحة الرابط: لمن الحساب وما نوع الرابط (بلا تفاصيل أخرى) */
    public function invite(string $token, Invites $inv): JsonResponse
    {
        $u = $inv->find($token);
        $state = $u ? Invites::state($u) : null;
        if (! $u || $state !== 'pending' || $u->blocked()) {
            return response()->json(['message' => L::t('الرابط غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً من مدير النظام، أو من «نسيت كلمة المرور؟».', 'This link is invalid or has expired. Ask your administrator for a new one, or use "Forgot password?".'),
                'code' => 'invite_invalid', 'expired' => $state === 'expired'], 410);
        }
        $co = $u->company_id ? Company::find($u->company_id) : null;
        return response()->json(['data' => ['email' => $u->email, 'name' => ['ar' => $u->displayName('ar'), 'en' => $u->displayName('en')], 'kind' => $u->invite_kind,
            'company' => $co ? ['ar' => $co->name_ar, 'en' => $co->name_en, 'logo' => $co->card()['logo']] : null,
            'expires_at' => $u->invite_expires_at?->toIso8601String(), 'password' => \App\Support\PasswordPolicy::client($u->company_id)]]);
    }

    /** اختيار كلمة المرور بالرابط ثم الدخول مباشرة */
    public function activate(Request $request, string $token, Invites $inv): JsonResponse
    {
        $cid = $inv->find($token)?->company_id;
        $request->validate(['password' => ['required', 'confirmed', self::passwordRule($cid)]], self::passwordMessages($cid));
        $user = $inv->accept($token, (string) $request->input('password'));
        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('auth.login', $user);
        return response()->json(['user' => $user->sessionPayload()]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($u = $request->user()) Audit::record('auth.logout', $u);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['user' => null]);
    }

    /** قاعدة كلمة المرور حسب سياسة شركة الحساب (المتقدمون: الافتراضية) */
    public static function passwordRule(?int $companyId = null): PasswordRule { return \App\Support\PasswordPolicy::rule($companyId); }

    public static function passwordMessages(?int $companyId = null): array { return \App\Support\PasswordPolicy::messages($companyId); }

    /** تغيير كلمة المرور من داخل الحساب (وإلزامياً بعد كلمة مؤقتة) */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', self::passwordRule($user->company_id), 'different:current_password'],
        ], self::passwordMessages($user->company_id) + ['current_password.current_password' => L::t('كلمة المرور الحالية غير صحيحة.', 'Current password is incorrect.'), 'current_password.required' => L::t('أدخل كلمة المرور الحالية.', 'Enter your current password.'), 'password.different' => L::t('اختر كلمة مرور مختلفة عن الحالية.', 'Choose a password different from the current one.')]);
        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false, 'activated_at' => $user->activated_at ?? now()])->save();
        Audit::record('auth.password_changed', $user);
        $request->session()->regenerate();
        return response()->json(['user' => $user->sessionPayload()]);
    }

    /** «نسيت كلمة المرور؟» — الرد واحد دائماً حتى لا يُكشف وجود الحساب */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']], ['email.required' => L::t('أدخل البريد الإلكتروني.', 'Enter your email.'), 'email.email' => L::t('صيغة البريد غير صحيحة.', 'Invalid email format.')]);
        $email = strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();
        if ($user && ! $user->blocked()) {
            Password::sendResetLink(['email' => $email]);
            Audit::record('auth.reset_requested', $user);
        }
        return response()->json(['message' => L::t('إذا كان البريد مسجلاً لدينا فستصلك رسالة فيها رابط لتعيين كلمة مرور جديدة خلال دقائق.', 'If this email is registered, you will receive a link to set a new password within minutes.')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $cid = User::where('email', strtolower(trim((string) $request->input('email'))))->value('company_id');
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', self::passwordRule($cid)]], self::passwordMessages($cid));
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null,
                'activated_at' => $user->activated_at ?? now(), 'invite_hash' => null, 'invite_kind' => null, 'invite_expires_at' => null])->save();
            Audit::record('auth.password_reset', $user);
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => L::t('رابط إعادة التعيين غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.', 'This reset link is invalid or has expired. Request a new one.'), 'code' => 'invalid_token'], 422);
        }
        return response()->json(['message' => L::t('تم تعيين كلمة المرور الجديدة. يمكنك تسجيل الدخول الآن.', 'Your new password is set. You can sign in now.')]);
    }
}
