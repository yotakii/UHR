<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Location;
use App\Services\Attendance\NfcGate;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * هوية الجهاز لواجهة الأحداث (FR-NF-003/004/019): معرّف ثابت + توقيع HMAC للرسالة بسرّ الجهاز، أو مفتاح الجهاز.
 * عنوان IP ليس هوية — قائمة العناوين المسموحة للفرع طبقة إضافية اختيارية فقط. الشركة والفرع من الجهاز حصراً.
 */
class RequireNfDevice
{
    public function handle(Request $request, Closure $next)
    {
        $deny = fn (string $code = 'device_unknown', int $status = 401) => response()->json(['message' => L::t('الجهاز غير مسجّل أو موقوف أو التوقيع غير صحيح.', 'Unknown or disabled device, or a bad signature.'), 'code' => $code], $status);
        $id = trim((string) $request->header('X-Device-Id'));
        $key = (string) $request->header('X-Device-Key');
        $sig = strtolower(trim((string) $request->header('X-Signature')));
        $d = null;
        if ($id !== '') $d = Device::allCompanies()->with('company')->where(fn ($q) => $q->where('code', $id)->when(ctype_digit($id), fn ($w) => $w->orWhere('id', (int) $id)))->first();
        elseif (strlen($key) >= 32) $d = Device::allCompanies()->with('company')->where('key_hash', hash('sha256', $key))->first();
        if (! $d || ! $d->active || $d->status !== 'active' || $d->company?->status !== 'active') return $deny();

        return Tenant::run($d->company, function () use ($request, $next, $d, $key, $sig, $deny) {
            $signed = $sig !== '' && $d->secret && hash_equals(hash_hmac('sha256', $request->getContent(), $d->secret), $sig);
            $keyed = strlen($key) >= 32 && hash_equals((string) $d->key_hash, hash('sha256', $key));
            $needSig = $d->require_signature || NfcGate::cfg()['signature'] === 'required';
            if ($sig !== '' && ! $signed) return $this->security($d, 'bad_signature', $deny);
            if ($needSig ? ! $signed : ! ($signed || $keyed)) return $this->security($d, 'unsigned', $deny);
            $loc = $d->location_id ? Location::find($d->location_id) : null;
            $ips = array_values(array_filter((array) ($loc?->allowed_ips ?? [])));
            if ($ips && ! IpUtils::checkIp((string) $request->ip(), $ips)) return $this->security($d, 'ip_not_allowed', fn () => $deny('ip_not_allowed', 403));
            $request->attributes->set('device', $d);
            return $next($request);
        });
    }

    /** رفض الرسالة كلياً وتنبيه أمني (مرة في الساعة لكل جهاز وسبب) */
    private function security(Device $d, string $why, Closure $deny)
    {
        if (Cache::add("nf-sec:{$d->id}:{$why}", 1, 3600)) {
            Audit::record('device.rejected', $d, ['reason' => $why, 'ip' => request()->ip()]);
            Notify::hr("رُفضت رسالة من الجهاز «{$d->name_ar}»: ".['bad_signature' => 'توقيع غير صحيح', 'unsigned' => 'رسالة غير موقّعة', 'ip_not_allowed' => 'عنوان غير مسموح'][$why],
                "A message from «{$d->name_en}» was rejected: ".['bad_signature' => 'bad signature', 'unsigned' => 'unsigned message', 'ip_not_allowed' => 'address not allowed'][$why], 'nfc:devices');
        }
        return $deny();
    }
}
