<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * أرشيف سجل التدقيق الدائم (SRS §10.14): السجل يُعرض في الواجهة آخر visible_days يوماً، وكل شهر انقضى خارج هذه النافذة
 * يُكتب ملفاً مضغوطاً (JSON Lines) في مستودع الشركة. لا يُحذف أي سطر من قاعدة البيانات.
 */
class AuditArchive
{
    public static function dir(?int $companyId): string { return $companyId ? "companies/{$companyId}/audit-archive" : 'platform/audit-archive'; }

    /** يكتب ملفات الأشهر المكتملة التي خرجت من نافذة العرض ولم تُكتب بعد — يُرجع عدد الملفات */
    public function run(): int
    {
        $n = 0;
        foreach ([null, ...Company::pluck('id')->all()] as $id) $n += Tenant::run($id, fn () => $this->company($id));
        return $n;
    }

    public function company(?int $id): int
    {
        $days = $id ? (int) Settings::merged('audit')['visible_days'] : 30;
        $edge = now()->subDays($days)->startOfMonth(); // الأشهر قبل هذا الشهر كاملة خارج النافذة
        $first = AuditLog::where('company_id', $id)->min('at');
        if (! $first) return 0;
        $n = 0;
        for ($m = \Illuminate\Support\Carbon::parse($first)->startOfMonth(); $m->lt($edge); $m->addMonth()) {
            $file = self::dir($id).'/'.$m->format('Y-m').'.jsonl.gz';
            if (Storage::disk('local')->exists($file)) continue;
            $gz = gzopen($tmp = tempnam(sys_get_temp_dir(), 'aud'), 'w9');
            $rows = 0;
            AuditLog::where('company_id', $id)->where('at', '>=', $m)->where('at', '<', $m->copy()->addMonth())->orderBy('id')
                ->chunkById(1000, function ($chunk) use ($gz, &$rows) {
                    foreach ($chunk as $r) { gzwrite($gz, json_encode($r->only(['id', 'at', 'user_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'ip', 'user_agent']), JSON_UNESCAPED_UNICODE)."\n"); $rows++; }
                });
            gzclose($gz);
            Storage::disk('local')->put($file, fopen($tmp, 'r'));
            @unlink($tmp);
            $n++;
        }
        return $n;
    }

    /** ملفات الأرشيف للشركة الحالية */
    public function files(): array
    {
        $d = self::dir(Tenant::id());
        return collect(Storage::disk('local')->files($d))->filter(fn ($f) => str_ends_with($f, '.jsonl.gz'))->sort()->reverse()
            ->map(fn ($f) => ['month' => basename($f, '.jsonl.gz'), 'size' => Storage::disk('local')->size($f)])->values()->all();
    }

    public function path(string $month): ?string
    {
        $f = self::dir(Tenant::id())."/{$month}.jsonl.gz";
        return preg_match('/^\d{4}-\d{2}$/', $month) && Storage::disk('local')->exists($f) ? Storage::disk('local')->path($f) : null;
    }
}
