<?php

namespace App\Support;

use App\Events\Changed;

/**
 * التحديث اللحظي (Reverb): كل كتابة مُدقَّقة تسجّل جدولها هنا، ويُبث حدث «uhr.changed» واحد على قناة الشركة في نهاية الطلب
 * (أو فوراً في الطابور والمجدول). الحمولة أسماء جداول فقط — بلا بيانات — فتعيد الواجهات المفتوحة تحميل ما تعرضه بصلاحياتها.
 * فشل خادم البث لا يُفشل أي عملية.
 */
class Realtime
{
    /** company_id => [table => true] */
    private static array $pending = [];
    private static ?int $hooked = null;   // التطبيق الذي سُجّل عليه تفريغ نهاية الطلب
    private static int $muted = 0;

    /** جداول لا تعني الشاشات المفتوحة (أو لها حدثها الخاص: الإشعارات) */
    public const IGNORE = ['audit_logs', 'mfa_audit', 'notices', 'sessions', 'jobs', 'failed_jobs'];

    public static function touch(string $table, ?int $companyId = null): void
    {
        $companyId ??= Tenant::id();
        if (! $companyId || in_array($table, self::IGNORE, true) || self::quiet()) return;
        self::$pending[$companyId][$table] = true;
        // نهاية الطلب أو الأمر (والطابور بعد كل مهمة — AppServiceProvider)
        if (self::$hooked !== spl_object_id(app())) { self::$hooked = spl_object_id(app()); app()->terminating(fn () => self::flush()); }
    }

    public static function mute(callable $fn): mixed
    {
        self::$muted++;
        try { return $fn(); } finally { self::$muted--; }
    }

    public static function flush(): void
    {
        $pending = self::$pending; self::$pending = [];
        foreach ($pending as $companyId => $tables) self::send(new Changed((int) $companyId, array_keys($tables), auth()->id()));
    }

    /** في الاختبارات: ما ينتظر البث */
    public static function pending(): array { return array_map('array_keys', self::$pending); }

    public static function enabled(): bool { return config('broadcasting.default') !== 'null' || app()->runningUnitTests(); }

    /** لا بث أثناء البذر والترحيل (Audit::mute) أو حين يكون معطلاً */
    private static function quiet(): bool { return self::$muted > 0 || Audit::muted() || ! self::enabled(); }

    public static function send(object $event): void
    {
        if (self::quiet()) return;
        try { event($event); } catch (\Throwable $e) { report($e); }
    }
}
