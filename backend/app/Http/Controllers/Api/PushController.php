<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use App\Services\Push\Fcm;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** تطبيق الجوال: تسجيل جهاز الإشعارات للمستخدم الحالي، وإعداد Firebase في لوحة المنصة */
class PushController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'min:20', 'max:512'], 'platform' => ['nullable', 'in:android,ios'], 'app_version' => ['nullable', 'string', 'max:20']]);
        $u = $request->user();
        // الرمز لجهاز واحد: إن سجّل مستخدم آخر دخوله على الجهاز نفسه ينتقل إليه
        PushDevice::updateOrCreate(['token' => $d['token']], ['user_id' => $u->id, 'company_id' => $u->company_id, 'platform' => $d['platform'] ?? 'android',
            'app_version' => $d['app_version'] ?? null, 'last_seen_at' => now()]);
        return response()->json(['ok' => true, 'enabled' => Fcm::enabled()]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:512']]);
        PushDevice::where('token', $d['token'])->where('user_id', $request->user()->id)->delete();
        return response()->json(['ok' => true]);
    }

    /** لوحة المنصة: الحالة دون كشف المفتاح */
    public function settings(): JsonResponse
    {
        $s = Settings::platformMerged('push'); $sa = Fcm::account();
        return response()->json(['data' => ['enabled' => (bool) $s['enabled'], 'configured' => (bool) $s['credentials'], 'project_id' => $sa['project_id'] ?? null,
            'devices' => PushDevice::count(), 'users' => PushDevice::distinct('user_id')->count('user_id')]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'credentials' => ['nullable', 'string', 'max:20000']]);
        $s = Settings::platformMerged('push');
        $s['enabled'] = (bool) $d['enabled'];
        if (filled($d['credentials'] ?? null)) {
            $j = json_decode($d['credentials'], true);
            if (! Fcm::valid($j)) return response()->json(['message' => L::t('الملف غير صالح: الصق محتوى ملف مفتاح حساب الخدمة (JSON) من إعدادات مشروع Firebase.', 'Invalid file: paste the service account key (JSON) from the Firebase project settings.'),
                'errors' => ['credentials' => [L::t('ملف غير صالح', 'Invalid file')]], 'code' => 'invalid_credentials'], 422);
            $s['credentials'] = Crypt::encryptString(json_encode($j));
        }
        Settings::putPlatform('push', $s);
        Audit::record('platform.push', null, null, ['enabled' => $s['enabled'], 'configured' => (bool) $s['credentials']]);
        return $this->settings();
    }

    /** إشعار تجريبي إلى أجهزة صاحب الحساب */
    public function test(Request $request): JsonResponse
    {
        if (! Fcm::enabled()) return response()->json(['message' => L::t('الإشعارات غير مفعّلة أو لم يُضف مفتاح Firebase.', 'Notifications are off or the Firebase key is missing.'), 'code' => 'push_off'], 409);
        try { $n = Fcm::toUser($request->user()->id, 'UHR', L::t('إشعار تجريبي من لوحة المنصة', 'Test notification from the platform console')); }
        catch (\Throwable $e) { return response()->json(['message' => $e->getMessage(), 'code' => 'push_failed'], 502); }
        return response()->json(['sent' => $n]);
    }
}
