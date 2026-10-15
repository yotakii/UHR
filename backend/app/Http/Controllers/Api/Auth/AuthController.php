<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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
    public const MAX_ATTEMPTS = 5;

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
            if ($fails >= self::MAX_ATTEMPTS) {
                $minutes = (int) Settings::policy('unlock_minutes');
                $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes($minutes)])->save();
                Audit::record('auth.locked', $user, null, ['minutes' => $minutes]);
                return $this->locked($minutes);
            }
            $user->forceFill(['failed_logins' => $fails])->save();
            Audit::record('auth.failed', $user, null, ['attempt' => $fails]);
            return $invalid(['remaining' => self::MAX_ATTEMPTS - $fails]);
        }

        if ($user->blocked()) {
            Audit::record('auth.blocked', $user);
            return response()->json(['message' => L::t('هذا الحساب موقوف. راجع الموارد البشرية.', 'This account is disabled. Please contact HR.'), 'code' => 'blocked'], 403);
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('auth.login', $user);
        return response()->json(['user' => $user->sessionPayload()]);
    }

    private function locked(int $minutes): JsonResponse
    {
        return response()->json(['message' => L::t("تم إيقاف الدخول مؤقتاً بعد عدة محاولات خاطئة. حاول بعد {$minutes} دقيقة، أو أعد تعيين كلمة المرور.", "Sign-in is paused after several wrong attempts. Try again in {$minutes} min, or reset your password."), 'code' => 'locked', 'minutes' => $minutes], 423);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($u = $request->user()) Audit::record('auth.logout', $u);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['user' => null]);
    }

    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min(8)->letters()->numbers();
    }

    public static function passwordMessages(): array { return [
        'password.required' => L::t('أدخل كلمة المرور الجديدة.', 'Enter the new password.'), 'password.confirmed' => L::t('كلمتا المرور غير متطابقتين.', 'Passwords do not match.'),
        'password.min' => L::t('كلمة المرور 8 أحرف على الأقل.', 'Password must be at least 8 characters.'), 'password.letters' => L::t('يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.', 'Password must contain at least one letter.'),
        'password.numbers' => L::t('يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.', 'Password must contain at least one number.'),
    ]; }

    /** تغيير كلمة المرور من داخل الحساب (وإلزامياً بعد كلمة مؤقتة) */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', self::passwordRule(), 'different:current_password'],
        ], self::passwordMessages() + ['current_password.current_password' => L::t('كلمة المرور الحالية غير صحيحة.', 'Current password is incorrect.'), 'current_password.required' => L::t('أدخل كلمة المرور الحالية.', 'Enter your current password.'), 'password.different' => L::t('اختر كلمة مرور مختلفة عن الحالية.', 'Choose a password different from the current one.')]);
        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();
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
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', self::passwordRule()]], self::passwordMessages());
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null])->save();
            Audit::record('auth.password_reset', $user);
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => L::t('رابط إعادة التعيين غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.', 'This reset link is invalid or has expired. Request a new one.'), 'code' => 'invalid_token'], 422);
        }
        return response()->json(['message' => L::t('تم تعيين كلمة المرور الجديدة. يمكنك تسجيل الدخول الآن.', 'Your new password is set. You can sign in now.')]);
    }
}
