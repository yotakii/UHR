<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\Role;
use App\Services\People\Directory;
use App\Services\People\EmployeeDocs;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** M07 — «الملفات الإضافية» في ملف الموظف وإعداداتها (SRS §10.11، D-25) */
class EmployeeDocController extends Controller
{
    public function __construct(private EmployeeDocs $docs) {}

    public function store(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'file' => ['required', 'file'], 'type' => ['required', 'string', 'max:30'], 'title' => ['nullable', 'string', 'max:190'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'], 'sensitive' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $e = Directory::scoped($request->user())->findOrFail($id);
        $doc = $this->docs->upload($e, $request->file('file'), $d, $request->user());
        return response()->json(['data' => EmployeeDocs::row($doc->load(['file', 'uploader']))], 201);
    }

    public function archive(Request $request, int $id, int $docId): JsonResponse
    {
        $restore = $request->boolean('restore');
        $doc = $this->doc($request, $id, $docId);
        $this->docs->archive($doc, $request->user(), $restore);
        return response()->json(['data' => EmployeeDocs::row($doc->fresh(['file', 'uploader']))]);
    }

    public function history(Request $request, int $id, int $docId): JsonResponse
    {
        return response()->json(['data' => EmployeeDocs::history($this->doc($request, $id, $docId))]);
    }

    /** إعدادات الملفات الإضافية (للقراءة: من يرى الموظفين؛ للتعديل: settings,edit) */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => Settings::docs(), 'defaults' => Settings::DEFAULTS['docs'],
            'roles' => Role::whereIn('key', Perm::ROLES)->get()->map(fn ($r) => ['key' => $r->key, 'ar' => $r->name_ar, 'en' => $r->name_en])]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'types' => ['required', 'array', 'min:1', 'max:40'], 'types.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,29}$/', 'distinct'],
            'types.*.ar' => ['required', 'string', 'max:80'], 'types.*.en' => ['required', 'string', 'max:80'],
            'extensions' => ['required', 'array', 'min:1', 'max:15'], 'extensions.*' => ['required', 'string', 'regex:/^[a-z0-9]{2,5}$/', 'distinct', 'not_in:php,phtml,exe,bat,cmd,sh,js,html,htm,svg'],
            'max_mb' => ['required', 'integer', 'between:1,50'], 'alert_days' => ['required', 'integer', 'between:0,365'],
            'sensitive_roles' => ['present', 'array'], 'sensitive_roles.*' => ['string', 'exists:roles,key'],
        ], [], ['types.*.key' => L::t('رمز النوع', 'Type code'), 'extensions.*' => L::t('الصيغة', 'Extension')]);
        // نوع مستخدم في مستندات قائمة لا يُحذف من القائمة
        $used = EmployeeDocument::distinct()->pluck('type')->diff(array_column($d['types'], 'key'));
        if ($used->isNotEmpty()) throw ValidationException::withMessages(['types' => [L::t('لا يمكن حذف نوع مستخدم في مستندات قائمة: ', 'A type used by existing documents cannot be removed: ').$used->implode(', ')]]);
        $before = Settings::docs();
        $new = ['types' => array_map(fn ($t) => ['key' => $t['key'], 'ar' => trim($t['ar']), 'en' => trim($t['en'])], $d['types']),
            'extensions' => array_values(array_map('strtolower', $d['extensions'])), 'max_mb' => (int) $d['max_mb'], 'alert_days' => (int) $d['alert_days'],
            'sensitive_roles' => array_values($d['sensitive_roles'])];
        Settings::put('docs', $new);
        Audit::record('settings.docs', null, $before, $new);
        return $this->settings();
    }

    private function doc(Request $request, int $id, int $docId): EmployeeDocument
    {
        $e = Directory::scoped($request->user())->findOrFail($id);
        $doc = EmployeeDocument::with('employee')->where('employee_id', $e->id)->findOrFail($docId);
        abort_if($doc->sensitive && $request->user()->employee_id !== $e->id && ! EmployeeDocs::canSensitive($request->user()), 404);
        return $doc;
    }
}
