<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Attendance\Attendance;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** شاشة جهاز الحضور وجسر القارئ (UHR-NFC-Bridge): بمفتاح الجهاز فقط — لا يُعرض إلا الاسم والمسمى ووقت الحركة */
class TerminalController extends Controller
{
    public function __construct(private Attendance $att) {}

    public function me(Request $request): JsonResponse
    {
        /** @var Device $d */
        $d = $request->attributes->get('device');
        $d->update(['last_seen_at' => now(), 'last_ip' => $request->ip()]);
        $c = Tenant::company();
        return response()->json(['device' => ['id' => $d->id, 'name' => ['ar' => $d->name_ar, 'en' => $d->name_en], 'kind' => $d->kind],
            'company' => ['slug' => $c->slug, 'name' => ['ar' => $c->short_ar ?: $c->name_ar, 'en' => $c->short_en ?: $c->name_en]],
            'feed' => Attendance::feed($d), 'cooldown' => (int) Settings::policy('terminal')['cooldown'], 'now' => now()->toIso8601String()]);
    }

    public function scan(Request $request): JsonResponse
    {
        $v = $request->validate(['uid' => ['required', 'string', 'max:60'], 'via' => ['nullable', 'in:usb,phone,bridge']]);
        $d = $request->attributes->get('device');
        $r = $this->att->scan($d, $v['uid'], $v['via'] ?? 'usb');
        return response()->json($r + ['feed' => Attendance::feed($d)]);
    }
}
