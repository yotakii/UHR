<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * ترقيم تسلسلي آمن مع التزامن (قفل الصف): UHR-1033، QR-2026-418، AST-0049 …
 * بديل Math.random() في الديمو — لا تكرار للأرقام أبداً.
 */
class Counters
{
    public static function next(string $key, int $start = 1): int
    {
        $key = self::scoped($key);
        return DB::transaction(function () use ($key, $start) {
            $row = DB::table('counters')->where('key', $key)->lockForUpdate()->first();
            if (! $row) {
                DB::table('counters')->insert(['key' => $key, 'value' => $start]);
                return $start;
            }
            $next = $row->value + 1;
            DB::table('counters')->where('key', $key)->update(['value' => $next]);
            return $next;
        });
    }

    /** يضمن ألا يقل العداد عن قيمة (بعد استيراد أو بيانات اختبار) */
    public static function atLeast(string $key, int $value): void
    {
        $key = self::scoped($key);
        $row = DB::table('counters')->where('key', $key)->first();
        if (! $row) DB::table('counters')->insert(['key' => $key, 'value' => $value]);
        elseif ($row->value < $value) DB::table('counters')->where('key', $key)->update(['value' => $value]);
    }

    /** الترقيم لكل شركة: c{id}:employee */
    private static function scoped(string $key): string { return ($c = Tenant::id()) ? "c{$c}:{$key}" : $key; }

    public static function employeeCode(): string { return Settings::prefix('employee').'-'.self::next('employee', 1001); }
    public static function assetTag(): string { return Settings::prefix('asset').'-'.str_pad((string) self::next('asset', 1), 4, '0', STR_PAD_LEFT); }
    public static function candidateRef(bool $internal = false): string
    {
        $y = now()->year; $p = Settings::prefix('candidate');
        return $internal ? "{$p}-{$y}-INT-".self::next("cand-int-{$y}", 1001) : "{$p}-{$y}-APP-".self::next("cand-app-{$y}", 48001);
    }
    public static function decisionNo(): string { $y = now()->year; return Settings::prefix('decision')."-{$y}-".self::next("decision-{$y}", 101); }
    public static function jobCode(): string { $y = now()->year; return Settings::prefix('job')."-{$y}-".str_pad((string) self::next("job-{$y}", 1), 3, '0', STR_PAD_LEFT); }
}
