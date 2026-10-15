<?php

namespace App\Support;

use App\Models\Notice;

/** إشعارات الجرس (notices): لكل الموارد البشرية، أو لمستخدم واحد — نفس notify() في الديمو */
class Notify
{
    public static function hr(string $ar, string $en, ?string $link = null): Notice
    {
        return Notice::create(['audience' => 'hr', 'user_id' => null, 'text_ar' => $ar, 'text_en' => $en, 'link' => $link, 'at' => now()]);
    }

    public static function user(?int $userId, string $ar, string $en, ?string $link = null): ?Notice
    {
        if (! $userId) return null;
        return Notice::create(['audience' => 'user', 'user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'link' => $link, 'at' => now()]);
    }
}
