<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * سجل التدقيق (SRS §8): من، ماذا، متى، القيمة قبل/بعد، من أي عنوان.
 * يُسجَّل تلقائياً لكل إنشاء/تعديل/حذف عبر Concerns\Auditable، ويدوياً لأحداث بلا نموذج (دخول، فتح الرواتب…).
 */
class Audit
{
    /** حقول لا تُكتب قيمتها أبداً في السجل */
    public const SECRET = ['password', 'remember_token', 'totp_secret', 'backup_hashes', 'config', 'key_hash', 'public_key'];
    /** حقول تتغير كثيراً بلا معنى تدقيقي */
    public const NOISE = ['updated_at', 'created_at', 'last_login_at', 'last_seen_at', 'failed_logins'];

    private static int $muted = 0;

    /** تنفيذ بلا تدقيق (البذر، الترحيل) */
    public static function mute(callable $fn): mixed
    {
        self::$muted++;
        try { return $fn(); } finally { self::$muted--; }
    }

    public static function muted(): bool { return self::$muted > 0; }

    public static function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): ?AuditLog
    {
        if (self::muted()) return null;
        $req = app()->bound('request') && ! app()->runningInConsole() ? request() : null;
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before ? self::clean($before) : null,
            'after' => $after ? self::clean($after) : null,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
            'at' => now(),
        ]);
    }

    public static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if (in_array($k, self::NOISE, true)) continue;
            $out[$k] = in_array($k, self::SECRET, true) ? '••••' : $v;
        }
        return $out;
    }
}
