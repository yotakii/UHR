<?php

namespace App\Support;

/** روابط يكتبها المستخدم بلا بروتوكول (www.example.com) ← تُكمَّل بـ https:// قبل التحقق والحفظ */
class Url
{
    public static function normalize(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $v) ? $v : 'https://'.ltrim($v, '/');
    }
}
