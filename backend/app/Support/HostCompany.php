<?php

namespace App\Support;

use App\Models\Company;

/**
 * بوابة الشركة (ملاحظة 86): يحدد الشركة من عنوان الطلب —
 * نطاقها الخاص (companies.domain، مثل hr.uniqueness.sy) أو {slug}.{base_domain} من إعداد المنصة portal_hosts.
 * لا شيء يتغير في العزل: البيانات معزولة بـ company_id دائماً، والبوابة هوية ودخول فقط.
 */
class HostCompany
{
    public static function host(?string $host): string
    {
        return strtolower(preg_replace('/:\d+$/', '', trim((string) $host)));
    }

    public static function resolve(?string $host): ?Company
    {
        $host = self::host($host);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return null;
        if ($c = Company::where('domain', $host)->where('status', 'active')->first()) return $c;
        $base = self::host(Settings::platformMerged('portal_hosts')['base_domain']);
        if ($base === '' || ! str_ends_with($host, '.'.$base)) return null;
        $slug = substr($host, 0, -strlen($base) - 1);
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $slug) || in_array($slug, ['www', 'api', 'app', 'mail'], true)) return null;
        return Company::where('slug', $slug)->where('status', 'active')->first();
    }

    /** عناوين بوابة الشركة للعرض */
    public static function urls(Company $c): array
    {
        $base = self::host(Settings::platformMerged('portal_hosts')['base_domain']);
        $front = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        return array_values(array_filter([
            $c->domain ? 'https://'.$c->domain : null,
            $base ? "https://{$c->slug}.{$base}" : null,
            "{$front}/c/{$c->slug}",
        ]));
    }
}
