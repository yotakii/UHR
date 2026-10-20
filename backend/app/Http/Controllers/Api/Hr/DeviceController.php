<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** M16 — أجهزة الحضور المسجّلة: الإضافة تعطي مفتاحاً يظهر مرة واحدة، والإيقاف/تجديد المفتاح يقطع الجهاز فوراً */
class DeviceController extends Controller
{
    public const KINDS = ['reader', 'sunmi', 'mobile', 'bridge'];

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
        $dev = Device::create($d + ['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'active' => true, 'created_by' => $request->user()->id]);
        return response()->json(['data' => self::row($dev->load('location')), 'key' => $key], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dev = Device::findOrFail($id);
        $dev->update($this->valid($request) + $request->validate(['active' => ['required', 'boolean']]));
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

    private function valid(Request $request): array
    {
        return $request->validate(['name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'], 'kind' => ['required', 'in:'.implode(',', self::KINDS)]]);
    }

    public static function row(Device $d): array
    {
        return ['id' => $d->id, 'name' => ['ar' => $d->name_ar, 'en' => $d->name_en], 'name_ar' => $d->name_ar, 'name_en' => $d->name_en, 'kind' => $d->kind,
            'location_id' => $d->location_id, 'location' => $d->location ? ['ar' => $d->location->name_ar, 'en' => $d->location->name_en] : null,
            'active' => $d->active, 'key_hint' => $d->key_hint, 'last_seen_at' => $d->last_seen_at?->toIso8601String(), 'last_ip' => $d->last_ip];
    }
}
