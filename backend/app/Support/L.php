<?php

namespace App\Support;

/** نص الـ API بلغة الواجهة (هيدر Accept-Language: ar|en) — نفس L(ar, en) في الواجهة */
class L
{
    public static function t(string $ar, string $en): string
    {
        return app()->getLocale() === 'en' ? $en : $ar;
    }
}
