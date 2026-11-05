<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * ملاحظة الاختبار 81: سياسة كلمات المرور ومحاولات الدخول — إعداد الشركة accounts (pw_min، pw_upper، pw_symbol، max_attempts، lock_minutes).
 * المتقدمون (بلا شركة) يأخذون القيم الافتراضية. الحرف والرقم مطلوبان دائماً.
 */
class PasswordPolicy
{
    public static function for(?int $companyId): array
    {
        $d = Settings::DEFAULTS['accounts'];
        $c = $companyId ? Tenant::run($companyId, fn () => Settings::merged('accounts')) : $d;
        return ['min' => (int) ($c['pw_min'] ?? $d['pw_min']), 'upper' => (bool) ($c['pw_upper'] ?? $d['pw_upper']), 'symbol' => (bool) ($c['pw_symbol'] ?? $d['pw_symbol']),
            'max_attempts' => (int) ($c['max_attempts'] ?? $d['max_attempts']), 'lock_minutes' => (int) ($c['lock_minutes'] ?? $d['lock_minutes'])];
    }

    public static function rule(?int $companyId = null): PasswordRule
    {
        $p = self::for($companyId);
        $r = PasswordRule::min($p['min'])->letters()->numbers();
        if ($p['upper']) $r->mixedCase();
        if ($p['symbol']) $r->symbols();
        return $r;
    }

    public static function messages(?int $companyId = null): array
    {
        $n = self::for($companyId)['min'];
        return [
            'password.required' => L::t('أدخل كلمة المرور الجديدة.', 'Enter the new password.'), 'password.confirmed' => L::t('كلمتا المرور غير متطابقتين.', 'Passwords do not match.'),
            'password.min' => L::t("كلمة المرور {$n} أحرف على الأقل.", "Password must be at least {$n} characters."), 'password.letters' => L::t('يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.', 'Password must contain at least one letter.'),
            'password.numbers' => L::t('يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.', 'Password must contain at least one number.'),
            'password.mixed' => L::t('يجب أن تحتوي كلمة المرور على حرف لاتيني كبير وآخر صغير على الأقل.', 'Password must contain at least one uppercase and one lowercase letter.'),
            'password.symbols' => L::t('يجب أن تحتوي كلمة المرور على رمز واحد على الأقل (مثل ! أو @).', 'Password must contain at least one symbol (such as ! or @).'),
        ];
    }

    /** ما يحتاجه المتصفح لعرض الشروط الحية */
    public static function client(?int $companyId): array
    {
        $p = self::for($companyId);
        return ['min' => $p['min'], 'upper' => $p['upper'], 'symbol' => $p['symbol']];
    }
}
