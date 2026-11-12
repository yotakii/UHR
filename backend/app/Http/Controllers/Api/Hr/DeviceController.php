<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M16 — أجهزة الحضور المسجّلة: الإضافة تعطي مفتاحاً يظهر مرة واحدة، والإيقاف/تجديد المفتاح يقطع الجهاز فوراً */
class DeviceController extends Controller
{
    public const KINDS = ['reader', 'sunmi', 'mobile', 'bridge', 'zk'];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Device::with('location')->orderByDesc('active')->orderBy('id')->get()->map(fn (Device $d) => self::row($d)),
            'locations' => Location::where('active', true)->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->valid($request);
        $key = Str::random(48);
        $d += $this->zkFields($request, null);
        $dev = Device::create($d + ['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'active' => true, 'created_by' => $request->user()->id]);
        return response()->json(['data' => self::row($dev->load('location')), 'key' => $key], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dev = Device::findOrFail($id);
        $dev->update($this->valid($request) + $request->validate(['active' => ['required', 'boolean']]) + $this->zkFields($request, $dev));
        return response()->json(['data' => self::row($dev->load('location'))]);
    }

    /** مفتاح جديد — الجهاز القديم يتوقف حتى يُدخل المفتاح الجديد */
    public function rotate(int $id): JsonResponse
    {
        $dev = Device::findOrFail($id);
        $key = Str::random(48);
        $dev->update(['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4)]);
        Audit::record('device.key_rotated', $dev);
        return response()->json(['data' => self::row($dev->load('location')), 'key' => $key]);
    }

    /**
     * الجسر جاهزاً للتثبيت: zip فيه bridge.ps1 وinstall.bat وملف الإعداد بعنوان النظام ومفتاح جديد لهذا الجهاز
     * (تجديد المفتاح يقطع أي نسخة سابقة من الجسر). التثبيت مرة واحدة ثم يعمل مع إقلاع ويندوز.
     */
    public function package(Request $request, int $id): BinaryFileResponse
    {
        $dev = Device::findOrFail($id);
        $key = Str::random(48);
        $dev->update(['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'kind' => 'bridge', 'active' => true]);
        Audit::record('device.bridge_package', $dev);
        $origin = $request->headers->get('origin') ?: (($r = $request->headers->get('referer')) ? preg_replace('#^(https?://[^/]+).*$#', '$1', $r) : null) ?: config('app.frontend_url');
        $src = resource_path('nfc-bridge');
        $path = tempnam(sys_get_temp_dir(), 'brg').'.zip';
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::CREATE);
        // ملفات ويندوز بنهايات CRLF مهما كانت نسخة المستودع (cmd يفشل مع LF أحياناً)
        foreach (scandir($src) as $f) if (is_file("{$src}/{$f}")) $zip->addFromString("UHR-NFC-Bridge/{$f}", preg_replace("/\r?\n/", "\r\n", file_get_contents("{$src}/{$f}")));
        $zip->addFromString('UHR-NFC-Bridge/uhr-bridge.ini', implode("\r\n", [
            '; UHR NFC Bridge — '.$dev->name_en.' ('.now()->toDateString().')',
            '; MODE=api يرسل للنظام مباشرة · MODE=keys يكتب الرقم في شاشة /kiosk المفتوحة', 'MODE=api', 'URL='.rtrim($origin, '/'), 'KEY='.$key, '']));
        $zip->close();
        return response()->download($path, 'UHR-NFC-Bridge.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** إعداد البصّامة: عنوانها في شبكة الشركة، المنفذ، كلمة الاتصال (Comm Key)، رقم الجهاز، ترتيب أرقام البطاقات */
    private function zkFields(Request $request, ?Device $dev): array
    {
        if ($request->input('kind') !== 'zk') return [];
        $v = $request->validate(['zk' => ['required', 'array'], 'zk.ip' => ['required', 'ip'], 'zk.port' => ['required', 'integer', 'between:1,65535'],
            'zk.comm_key' => ['nullable', 'regex:/^\d{0,9}$/'], 'zk.machine_no' => ['required', 'integer', 'between:1,255'], 'zk.card_order' => ['required', 'in:normal,reversed']], [],
            ['zk.ip' => L::t('عنوان البصّامة', 'Device IP'), 'zk.port' => L::t('المنفذ', 'Port'), 'zk.comm_key' => L::t('كلمة الاتصال', 'Comm key'), 'zk.machine_no' => L::t('رقم الجهاز', 'Machine no.')]);
        $old = $dev ? \App\Services\Attendance\ZkSync::cfg($dev) : \App\Services\Attendance\ZkSync::DEFAULTS;
        return ['zk' => array_merge($old, ['ip' => $v['zk']['ip'], 'port' => (int) $v['zk']['port'], 'comm_key' => (string) ($v['zk']['comm_key'] ?? ''), 'machine_no' => (int) $v['zk']['machine_no'], 'card_order' => $v['zk']['card_order']])];
    }

    /** جسر البصّامة جاهزاً: zip فيه zk-bridge.ps1 والتثبيت وملف الإعداد بعنوان النظام ومفتاح جديد (يقطع أي نسخة سابقة) */
    public function zkPackage(Request $request, int $id): BinaryFileResponse
    {
        $dev = Device::findOrFail($id);
        if ($dev->kind !== 'zk') throw new \App\Exceptions\ApiException(L::t('هذا الجهاز ليس بصّامة.', 'This device is not a fingerprint terminal.'), 'not_zk', 409);
        $key = Str::random(48);
        $dev->update(['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'active' => true]);
        Audit::record('device.zk_package', $dev);
        $origin = $request->headers->get('origin') ?: (($r = $request->headers->get('referer')) ? preg_replace('#^(https?://[^/]+).*$#', '$1', $r) : null) ?: config('app.frontend_url');
        $src = resource_path('zk-bridge');
        $path = tempnam(sys_get_temp_dir(), 'zkb').'.zip';
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::CREATE);
        foreach (scandir($src) as $f) if (is_file("{$src}/{$f}")) $zip->addFromString("UHR-ZK-Bridge/{$f}", preg_replace("/\r?\n/", "\r\n", file_get_contents("{$src}/{$f}")));
        $zip->addFromString('UHR-ZK-Bridge/uhr-zk.ini', implode("\r\n", ['; UHR ZK Bridge — '.$dev->name_en.' ('.now()->toDateString().')', 'URL='.rtrim($origin, '/'), 'KEY='.$key, '']));
        $zip->close();
        return response()->download($path, 'UHR-ZK-Bridge.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function zk(int $id): JsonResponse
    {
        $dev = Device::findOrFail($id);
        return response()->json(['data' => self::row($dev->load('location'))] + \App\Services\Attendance\ZkSync::overview($dev));
    }

    /** إعادة إرسال كل الموظفين للبصّامة في الدورة التالية */
    public function zkResync(int $id): JsonResponse
    {
        $dev = Device::findOrFail($id);
        $c = \App\Services\Attendance\ZkSync::cfg($dev); $c['force'] = (int) $c['force'] + 1;
        $dev->update(['zk' => $c]);
        return response()->json(['data' => self::row($dev->load('location'))]);
    }

    public function zkMap(Request $request, \App\Services\Attendance\ZkSync $zk): JsonResponse
    {
        $v = $request->validate(['pin' => ['required', 'string', 'max:20'], 'employee_id' => ['required', 'integer']]);
        $e = \App\Models\Employee::findOrFail($v['employee_id']);
        $n = $zk->map($v['pin'], $e, $request->user());
        return response()->json(['applied' => $n]);
    }

    private function valid(Request $request): array
    {
        return $request->validate(['name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'], 'kind' => ['required', 'in:'.implode(',', self::KINDS)]]);
    }

    public static function row(Device $d): array
    {
        return ['id' => $d->id, 'name' => ['ar' => $d->name_ar, 'en' => $d->name_en], 'name_ar' => $d->name_ar, 'name_en' => $d->name_en, 'kind' => $d->kind,
            'location_id' => $d->location_id, 'location' => $d->location ? ['ar' => $d->location->name_ar, 'en' => $d->location->name_en] : null,
            'zk' => $d->kind === 'zk' ? array_diff_key(\App\Services\Attendance\ZkSync::cfg($d), ['force' => 1]) : null, 'last_sync' => $d->last_sync, 'last_sync_at' => $d->last_sync_at?->toIso8601String(),
            'active' => $d->active, 'online' => $d->last_seen_at && $d->last_seen_at->gt(now()->subMinutes(3)), 'key_hint' => $d->key_hint, 'last_seen_at' => $d->last_seen_at?->toIso8601String(), 'last_ip' => $d->last_ip];
    }
}
