<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Attendance\NfcGate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * واجهة أحداث الأجهزة (SRS الأنظمة الأساسية C.3): الجهاز أو بوابة الفرع يدفع الأحداث إلى الخادم (Push)،
 * ويقبل دفعات مخزّنة بأوقاتها الأصلية بعد انقطاع الإنترنت، فتُعالَج بالترتيب الزمني دون تكرار (رقم seq).
 */
class NfController extends Controller
{
    /** التفعيل برمز لمرة واحدة (WF-NF-01) — لا جلسة ولا مفتاح بعد */
    public function activate(Request $request): JsonResponse
    {
        $v = $request->validate(['code' => ['required', 'string', 'max:20'], 'serial' => ['nullable', 'string', 'max:60'], 'lan_ip' => ['nullable', 'ip']]);
        $out = NfcGate::activate($v['code']);
        if (! empty($v['serial']) || ! empty($v['lan_ip'])) Device::allCompanies()->where(fn ($q) => $q->where('code', $out['device_id'])->orWhere('id', (int) $out['device_id']))
            ->update(array_filter(['serial' => $v['serial'] ?? null, 'lan_ip' => $v['lan_ip'] ?? null]));
        return response()->json($out);
    }

    public function events(Request $request, NfcGate $gate): JsonResponse
    {
        $v = $request->validate(['events' => ['required', 'array', 'min:1', 'max:500'], 'events.*.card' => ['required', 'string', 'max:60'],
            'events.*.at' => ['nullable', 'date'], 'events.*.seq' => ['nullable', 'integer', 'min:0'], 'events.*.via' => ['nullable', 'in:nfc,zk'], 'events.*.token' => ['nullable', 'string', 'max:64']]);
        /** @var Device $d */
        $d = $request->attributes->get('device');
        $events = collect($v['events'])->map(fn ($e) => $e + ['at' => $e['at'] ?? now()->toIso8601String()])->sortBy(fn ($e) => Carbon::parse($e['at'])->getTimestamp())->values();
        $out = [];
        foreach ($events as $e) {
            $at = Carbon::parse($e['at']);
            if ($at->isAfter(now()->addMinutes(5))) { $out[] = ['seq' => $e['seq'] ?? null, 'decision' => 'rejected', 'reason' => 'future']; continue; }
            $out[] = $gate->event($d, $e['card'], $at, isset($e['seq']) ? (int) $e['seq'] : null, $e['via'] ?? 'nfc', false, $e['token'] ?? null);
        }
        NfcGate::heartbeat($d, $request->only(['lan_ip', 'version', 'buffered', 'clock']));
        return response()->json(['results' => array_map(fn ($r) => array_intersect_key($r, array_flip(['seq', 'decision', 'reason', 'name', 'direction', 'time'])), $out)]);
    }

    /** نبضة كل بضع دقائق: حالة الاتصال وفرق الساعة وعدد الأحداث المخزّنة في البوابة */
    public function heartbeat(Request $request): JsonResponse
    {
        $v = $request->validate(['lan_ip' => ['nullable', 'ip'], 'version' => ['nullable', 'string', 'max:20'], 'buffered' => ['nullable', 'integer', 'min:0'], 'clock' => ['nullable', 'date']]);
        $d = $request->attributes->get('device');
        NfcGate::heartbeat($d, $v);
        $d->refresh()->load('location');
        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String(), 'device' => ['id' => $d->code ?: $d->id, 'name' => ['ar' => $d->name_ar, 'en' => $d->name_en], 'direction' => $d->direction,
            'branch' => $d->location ? ['ar' => $d->location->name_ar, 'en' => $d->location->name_en, 'timezone' => $d->location->timezone] : null]]);
    }
}
