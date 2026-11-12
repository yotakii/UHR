<?php

namespace App\Http\Controllers\Api\Device;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Attendance\ZkSync;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** جسر بصّامة ZKTeco (بمفتاح الجهاز): الإعداد وقائمة الموظفين، الحركات، وحالة الاتصال بالبصّامة */
class ZkController extends Controller
{
    public function __construct(private ZkSync $zk) {}

    private function device(Request $request): Device
    {
        /** @var Device $d */
        $d = $request->attributes->get('device');
        if ($d->kind !== 'zk') throw new ApiException(L::t('هذا الجهاز ليس بصّامة.', 'This device is not a fingerprint terminal.'), 'not_zk', 409);
        return $d;
    }

    public function config(Request $request): JsonResponse
    {
        $d = $this->device($request);
        $d->update(['last_seen_at' => now(), 'last_ip' => $request->ip()]);
        return response()->json(ZkSync::config($d));
    }

    public function punches(Request $request): JsonResponse
    {
        $d = $this->device($request);
        $v = $request->validate(['punches' => ['present', 'array', 'max:'.ZkSync::MAX_BATCH], 'punches.*.pin' => ['required'], 'punches.*.time' => ['required', 'string'],
            'info' => ['nullable', 'array']]);
        $r = $this->zk->ingest($d, $v['punches']);
        $d->update(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'last_sync_at' => now(),
            'last_sync' => ['ok' => true, 'error' => null, 'info' => $v['info'] ?? ($d->last_sync['info'] ?? null)] + $r]);
        return response()->json($r);
    }

    /** الجسر يبلّغ: تعذّر الوصول للبصّامة / تم الاتصال (مع معلومات الجهاز) */
    public function status(Request $request): JsonResponse
    {
        $d = $this->device($request);
        $v = $request->validate(['ok' => ['required', 'boolean'], 'error' => ['nullable', 'string', 'max:300'], 'info' => ['nullable', 'array']]);
        $was = $d->last_sync['ok'] ?? null;
        $d->update(['last_seen_at' => now(), 'last_ip' => $request->ip(),
            'last_sync' => array_merge((array) $d->last_sync, ['ok' => $v['ok'], 'error' => $v['error'] ?? null, 'at' => now()->toIso8601String()] + (isset($v['info']) ? ['info' => $v['info']] : []))]);
        if ($was !== $v['ok']) \App\Support\Realtime::touch('devices', $d->company_id);
        return response()->json(['ok' => true]);
    }
}
