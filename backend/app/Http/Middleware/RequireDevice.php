<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\L;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * جهاز حضور مسجّل (SRS §10.10): المفتاح في الهيدر X-Device-Key (يُعرض مرة واحدة عند التسجيل ويُخزَّن مشفّراً sha256)،
 * والطلب يجري داخل شركة الجهاز. جهاز موقوف أو شركة معلّقة ← 401.
 */
class RequireDevice
{
    public function handle(Request $request, Closure $next)
    {
        $key = (string) $request->header('X-Device-Key');
        $d = strlen($key) >= 32 ? Device::allCompanies()->with('company')->where('key_hash', hash('sha256', $key))->first() : null;
        if (! $d || ! $d->active || $d->company?->status !== 'active') {
            return response()->json(['message' => L::t('الجهاز غير مسجّل أو موقوف — راجع الموارد البشرية.', 'This device is not registered or was disabled — contact HR.'), 'code' => 'device_unknown'], 401);
        }
        $request->attributes->set('device', $d);
        return Tenant::run($d->company, fn () => $next($request));
    }
}
