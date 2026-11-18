<?php

namespace App\Http\Controllers\Api\Employee;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Location;
use App\Services\Attendance\NfcGate;
use App\Services\People\Portal;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * الحضور بالهاتف بمسح ملصق الفرع (FR-NF-017): الملصق يحمل رمز الفرع، والموظف يفتح الرابط من حسابه،
 * فيتحقق الخادم من الحساب والموقع الجغرافي ضمن نطاق الفرع ثم يمر بفحوص البوابة نفسها (الفرع، التكرار…).
 */
class StickerController extends Controller
{
    public function info(Request $request, string $token): JsonResponse
    {
        $l = $this->branch($token);
        return response()->json(['branch' => ['ar' => $l->name_ar, 'en' => $l->name_en], 'geo' => $this->needsGeo($l), 'employee' => (bool) $request->user()->employee_id]);
    }

    public function checkin(Request $request, string $token, NfcGate $gate): JsonResponse
    {
        $l = $this->branch($token);
        $v = $request->validate(['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'], 'device_token' => ['nullable', 'string', 'max:120']]);
        $e = $request->user()->employee_id ? Employee::find($request->user()->employee_id) : null;
        if (! $e) throw new ApiException(L::t('الحضور للموظفين فقط.', 'Check-in is for employees only.'), 'not_employee', 403);
        NfcGate::phoneCheck($e, $v['device_token'] ?? null);
        if ($this->needsGeo($l)) {
            if (! isset($v['lat'], $v['lng'])) throw new ApiException(L::t('اسمح بتحديد الموقع لتسجيل الحضور من ملصق الفرع.', 'Allow location access to check in with the branch sticker.'), 'location_required', 422);
            $m = Portal::distance((float) $v['lat'], (float) $v['lng'], (float) $l->lat, (float) $l->lng);
            if ($m > $l->radius) throw new ApiException(L::t('أنت خارج نطاق الفرع ('.round($m).' م).', 'You are outside the branch area ('.round($m).' m).'), 'out_of_range', 422);
        }
        $d = Device::firstOrCreate(['kind' => 'sticker', 'location_id' => $l->id], ['name_ar' => "ملصق الفرع — {$l->name_ar}", 'name_en' => "Branch sticker — {$l->name_en}",
            'key_hash' => hash('sha256', Str::random(64)), 'active' => true, 'status' => 'active', 'direction' => 'both']);
        $d->update(['last_seen_at' => now()]);
        $r = $gate->person($d, $e, now(), 'sticker');
        if ($r['decision'] === 'rejected') throw new ApiException(self::why($r['reason']), $r['reason'], 422);
        return response()->json(['data' => $r + ['branch' => ['ar' => $l->name_ar, 'en' => $l->name_en]]]);
    }

    private function branch(string $token): Location
    {
        if (! NfcGate::cfg()['sticker']) throw new ApiException(L::t('الحضور بملصق الفرع غير مفعّل.', 'Branch sticker check-in is off.'), 'sticker_off', 409);
        return Location::where('scan_token', $token)->where('active', true)->first() ?? throw new ApiException(L::t('الملصق غير صالح أو تم تجديده.', 'This sticker is invalid or was replaced.'), 'sticker_invalid', 404);
    }

    private function needsGeo(Location $l): bool { return NfcGate::cfg()['sticker_geo'] && $l->radius > 0 && $l->lat !== null && $l->lng !== null; }

    public static function why(?string $r): string
    {
        return match ($r) {
            'off_branch' => L::t('ليس لديك صلاحية الحضور في هذا الفرع اليوم.', 'You are not authorised to check in at this branch today.'),
            'inactive' => L::t('حسابك غير فعّال للحضور.', 'Your account is not active for attendance.'),
            default => L::t('رُفض التسجيل.', 'Check-in was refused.'),
        };
    }
}
