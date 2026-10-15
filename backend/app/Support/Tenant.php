<?php

namespace App\Support;

use App\Models\Company;

/**
 * الشركة الحالية للطلب (SRS §10.3). تُضبط من حساب المستخدم أو من الشاغر/الشركة في الطلبات العامة،
 * وكل نموذج يحمل BelongsToCompany يُفلتر بها تلقائياً ويأخذها عند الإنشاء.
 * بلا شركة (مدير المنصة، المتقدم، الأوامر) لا فلترة — والاستعلام يحدد الشركة صراحةً.
 */
class Tenant
{
    private static ?int $id = null;
    private static ?Company $company = null;

    public static function id(): ?int { return self::$id; }

    public static function set(Company|int|null $company): void
    {
        self::$company = $company instanceof Company ? $company : null;
        self::$id = $company instanceof Company ? $company->id : $company;
    }

    public static function company(): ?Company
    {
        if (! self::$id) return null;
        return self::$company ??= Company::find(self::$id);
    }

    /** تنفيذ ضمن شركة ثم إعادة السياق السابق (البذر، الطوابير، عمليات المتقدم على طلب في شركة) */
    public static function run(Company|int|null $company, callable $fn): mixed
    {
        [$id, $c] = [self::$id, self::$company];
        self::set($company);
        try { return $fn(); } finally { self::$id = $id; self::$company = $c; }
    }

    public static function forget(): void { self::$id = null; self::$company = null; }
}
