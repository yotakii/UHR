<?php

namespace App\Support;

use App\Exceptions\ApiException;

/**
 * بريد حسابات الموظفين الرسمي: إعداد الشركة accounts.email_domains (مثل uniqueness.sy).
 * عند تحديد نطاقات لا يُقبل بريد خارجها لحساب موظف، ولا يُرسل رابط كلمة المرور إلى بريد خارجها (يذهب الطلب إلى الموارد البشرية).
 * المتقدمون للوظائف (بلا شركة) يستخدمون بريدهم الشخصي ولا ينطبق عليهم هذا.
 */
class AccountEmail
{
    /** @return string[] */
    public static function domains(?int $companyId = null): array
    {
        $companyId ??= Tenant::id();
        if (! $companyId) return [];
        $c = Tenant::run($companyId, fn () => Settings::merged('accounts'));
        return array_values(array_filter(array_map(fn ($d) => strtolower(trim((string) $d)), (array) ($c['email_domains'] ?? []))));
    }

    public static function resetMode(?int $companyId): string
    {
        if (! $companyId) return 'email';
        $c = Tenant::run($companyId, fn () => Settings::merged('accounts'));
        return ($c['reset_mode'] ?? 'email') === 'hr' ? 'hr' : 'email';
    }

    public static function allowed(string $email, ?int $companyId = null): bool
    {
        $domains = self::domains($companyId);
        if (! $domains) return true;
        $host = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach ($domains as $d) if ($host === $d || str_ends_with($host, '.'.$d)) return true;
        return false;
    }

    public static function message(?int $companyId = null): string
    {
        $list = implode('، ', array_map(fn ($d) => '@'.$d, self::domains($companyId)));
        $listEn = implode(', ', array_map(fn ($d) => '@'.$d, self::domains($companyId)));
        return L::t("يجب أن يكون البريد من بريد الشركة الرسمي ({$list}).", "The email must be a company email ({$listEn}).");
    }

    /** يرفض بريداً خارج نطاقات الشركة (422 تحت الحقل) */
    public static function check(string $email, string $field = 'email', ?int $companyId = null): void
    {
        if (! self::allowed($email, $companyId)) throw ApiException::field($field, self::message($companyId), 'email_domain');
    }
}
