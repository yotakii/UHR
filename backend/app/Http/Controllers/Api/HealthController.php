<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * فحص جاهزية البنية: قاعدة البيانات، Redis، التخزين.
 * 200 إذا كل شي سليم، 503 إذا أي خدمة متوقفة.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(function () {
                $version = DB::selectOne('select version() as v')->v;
                $migrations = Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0;

                return [
                    'version' => preg_replace('/^(PostgreSQL [\d.]+).*/', '$1', $version),
                    'migrations' => $migrations,
                ];
            }),
            'redis' => $this->probe(function () {
                $key = 'uhr:health:'.uniqid();
                Redis::setex($key, 10, 'ok');
                if (Redis::get($key) !== 'ok') {
                    throw new \RuntimeException('Redis read-back mismatch');
                }
                Redis::del($key);
                $info = Redis::info('server');

                return ['version' => $info['Server']['redis_version'] ?? $info['redis_version'] ?? null];
            }),
            'storage' => $this->probe(function () {
                $file = '.health-'.uniqid();
                Storage::disk('local')->put($file, 'ok');
                $ok = Storage::disk('local')->get($file) === 'ok';
                Storage::disk('local')->delete($file);
                if (! $ok) {
                    throw new \RuntimeException('Storage read-back mismatch');
                }

                return [];
            }),
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'app' => [
                'name' => config('app.name'),
                'env' => app()->environment(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'timezone' => config('app.timezone'),
                'time' => now()->toIso8601String(),
            ],
            'checks' => $checks,
        ], $allOk ? 200 : 503);
    }

    /** @param callable(): array<string,mixed> $fn */
    private function probe(callable $fn): array
    {
        $t0 = hrtime(true);
        try {
            $extra = $fn();

            return ['ok' => true, 'ms' => $this->ms($t0)] + $extra;
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => $this->ms($t0), 'error' => $e->getMessage()];
        }
    }

    private function ms(int $t0): int
    {
        return (int) round((hrtime(true) - $t0) / 1e6);
    }
}
