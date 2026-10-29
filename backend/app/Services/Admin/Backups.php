<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Backup;
use App\Models\Company;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * النسخ الاحتياطي (M16، D-27 «محلي على سيرفر العميل + نسخة يومية»):
 * - نسخة الخادم الكاملة: pg_dump بصيغة custom (للاستعادة بـ pg_restore)، يومياً في وقت إعداد المنصة، والاحتفاظ بآخر keep.
 * - نسخة بيانات الشركة: zip بصفوف كل جدول للشركة (JSON) + ملفاتها المرفوعة، بلا أسرار (كلمات المرور، مفاتيح التحقق).
 */
class Backups
{
    /** أعمدة لا تُنسخ في نسخة الشركة */
    private const SECRET_COLUMNS = ['password', 'remember_token', 'totp_secret', 'backup_hashes', 'key_hash', 'public_key', 'token'];

    public function full(?User $by = null, string $trigger = 'manual'): Backup
    {
        if (Backup::whereNull('company_id')->where('status', 'running')->where('started_at', '>', now()->subHour())->exists())
            throw new ApiException(L::t('نسخة احتياطية قيد التنفيذ الآن.', 'A backup is already running.'), 'backup_running', 409);
        $b = Backup::create(['company_id' => null, 'kind' => 'full', 'trigger' => $trigger, 'status' => 'running', 'created_by' => $by?->id, 'started_at' => now()]);
        $rel = 'backups/full/uhr-'.now()->format('Ymd-His').'.dump';
        try {
            $bin = (new ExecutableFinder())->find('pg_dump') ?? throw new \RuntimeException('pg_dump is not installed');
            $c = config('database.connections.'.config('database.default'));
            Storage::disk('local')->makeDirectory('backups/full');
            $p = new Process([$bin, '-h', $c['host'], '-p', (string) $c['port'], '-U', $c['username'], '-Fc', '--no-owner', '-f', Storage::disk('local')->path($rel), $c['database']], null, ['PGPASSWORD' => $c['password']]);
            $p->setTimeout(1800);
            $p->run();
            if (! $p->isSuccessful()) throw new \RuntimeException(trim($p->getErrorOutput()) ?: 'pg_dump failed');
            $b->update(['status' => 'ok', 'path' => $rel, 'size' => Storage::disk('local')->size($rel), 'finished_at' => now(),
                'meta' => ['tables' => count($this->tables()), 'pg_dump' => trim((new Process([$bin, '--version']))->mustRun()->getOutput())]]);
            Tenant::run(null, fn () => Audit::record('backup.created', $b, null, ['kind' => 'full', 'trigger' => $trigger, 'size' => $b->size]));
            $this->prune(null, (int) Settings::platformMerged('backup')['keep']);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($rel);
            $b->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            report($e);
        }
        return $b->refresh();
    }

    public function company(Company $company, ?User $by = null, string $trigger = 'manual'): Backup
    {
        return Tenant::run($company->id, function () use ($company, $by, $trigger) {
            $cfg = Settings::merged('backup');
            $b = Backup::create(['company_id' => $company->id, 'kind' => 'company', 'trigger' => $trigger, 'status' => 'running', 'created_by' => $by?->id, 'started_at' => now()]);
            $rel = "companies/{$company->id}/backups/{$company->slug}-".now()->format('Ymd-His').'.zip';
            $tmp = tempnam(sys_get_temp_dir(), 'bk');
            try {
                $zip = new \ZipArchive();
                if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Cannot create the zip file');
                $counts = [];
                foreach ($this->tables() as $table) {
                    $cols = array_diff($this->columns($table), self::SECRET_COLUMNS);
                    $rows = DB::table($table)->where('company_id', $company->id)->orderBy(in_array('id', $cols, true) ? 'id' : $cols[0])->get($cols);
                    if ($rows->isEmpty()) continue;
                    $zip->addFromString("tables/{$table}.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $counts[$table] = $rows->count();
                }
                $files = 0;
                if ($cfg['include_files']) foreach (StoredFile::where('company_id', $company->id)->get(['id', 'disk', 'path', 'name']) as $f) {
                    $disk = Storage::disk($f->disk ?: 'local');
                    if ($f->path && $disk->exists($f->path)) { $zip->addFile($disk->path($f->path), 'files/'.$f->id.'-'.preg_replace('/[\\\\\/:*?"<>|]/u', '_', (string) $f->name)); $files++; }
                }
                $manifest = ['company' => ['id' => $company->id, 'slug' => $company->slug, 'name_ar' => $company->name_ar, 'name_en' => $company->name_en],
                    'created_at' => now()->toIso8601String(), 'tables' => $counts, 'files' => $files, 'app' => config('app.name'),
                    'migration' => DB::table('migrations')->orderByDesc('id')->value('migration'), 'note' => 'Secrets (passwords, verification keys) are not included.'];
                $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $zip->close();
                Storage::disk('local')->put($rel, fopen($tmp, 'r'));
                $b->update(['status' => 'ok', 'path' => $rel, 'size' => Storage::disk('local')->size($rel), 'finished_at' => now(), 'meta' => ['tables' => count($counts), 'rows' => array_sum($counts), 'files' => $files]]);
                Audit::record('backup.created', $b, null, ['kind' => 'company', 'trigger' => $trigger, 'size' => $b->size, 'rows' => array_sum($counts), 'files' => $files]);
                $this->prune($company->id, (int) $cfg['keep']);
            } catch (\Throwable $e) {
                $b->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
                report($e);
            } finally {
                @unlink($tmp);
            }
            return $b->refresh();
        });
    }

    /** يُبقي آخر keep نسخة ناجحة ويحذف ملفات الأقدم (يبقى سطرها في السجل بحالة removed) */
    public function prune(?int $companyId, int $keep): int
    {
        $old = Backup::where('company_id', $companyId)->where('status', 'ok')->orderByDesc('id')->get()->slice(max(1, $keep));
        foreach ($old as $b) { if ($b->path) Storage::disk('local')->delete($b->path); $b->update(['status' => 'removed', 'path' => null]); }
        return $old->count();
    }

    /** النسخة الآلية اليومية: بعد وقت الإعداد ولم تُنفَّذ اليوم */
    public function due(): bool
    {
        $cfg = Settings::platformMerged('backup');
        if (! $cfg['enabled']) return false;
        [$h, $m] = array_map('intval', explode(':', $cfg['time']));
        $at = now()->setTime($h, $m);
        return now()->gte($at) && ! Backup::whereNull('company_id')->where('trigger', 'auto')->whereIn('status', ['ok', 'running'])->where('started_at', '>=', $at)->exists();
    }

    public function file(Backup $b): string
    {
        if ($b->status !== 'ok' || ! $b->path || ! Storage::disk('local')->exists($b->path)) throw new ApiException(L::t('ملف النسخة غير موجود.', 'The backup file is not available.'), 'backup_missing', 404);
        return Storage::disk('local')->path($b->path);
    }

    public static function row(Backup $b): array
    {
        return ['id' => $b->id, 'kind' => $b->kind, 'trigger' => $b->trigger, 'status' => $b->status, 'size' => $b->size, 'meta' => $b->meta, 'error' => $b->error,
            'name' => $b->path ? basename($b->path) : null, 'started_at' => $b->started_at?->toIso8601String(), 'finished_at' => $b->finished_at?->toIso8601String(),
            'by' => $b->relationLoaded('creator') && $b->creator ? ['ar' => $b->creator->name, 'en' => $b->creator->name_en ?: $b->creator->name] : null];
    }

    /** الجداول التي فيها company_id */
    private function tables(): array
    {
        return DB::table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'company_id')->orderBy('table_name')->pluck('table_name')
            ->reject(fn ($t) => in_array($t, ['backups', 'sessions'], true))->values()->all();
    }

    private function columns(string $table): array
    {
        return DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->orderBy('ordinal_position')->pluck('column_name')->all();
    }
}
