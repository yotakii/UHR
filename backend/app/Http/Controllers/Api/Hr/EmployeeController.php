<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Resources\HrOnboarding;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Services\People\EmployeeImport;
use App\Services\People\NewEmployee;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M06 — معالج إضافة موظف واستيراد الموظفين الحاليين (الدليل والملف في DirectoryController) */
class EmployeeController extends Controller
{
    /** قوائم المعالج ونموذج التعديل: الإدارات (برئيسها)، المقرات، المدراء المحتملون، والعهد المتاحة لمن يضيف موظفين */
    public function lookups(Request $request): JsonResponse
    {
        return response()->json([
            'departments' => Department::with('head')->where('active', true)->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'ar' => $d->name_ar, 'en' => $d->name_en, 'head' => $d->head ? ['ar' => $d->head->name, 'en' => $d->head->name_en] : null]),
            'locations' => Location::where('active', true)->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'managers' => Employee::current()->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'department_id'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'department_id' => $e->department_id]),
            'assets' => ! Perm::can($request->user(), 'employees', 'create') ? [] : Asset::with('category')->where('status', 'available')->orderBy('asset_category_id')->orderBy('tag')->get()->map(fn ($a) => HrOnboarding::asset($a)),
            'currencies' => NewEmployee::CURRENCIES,
        ]);
    }

    public function store(Request $request, NewEmployee $factory): JsonResponse
    {
        $d = $request->validate(['asset_ids' => ['array', 'max:20'], 'asset_ids.*' => ['integer']]);
        $e = $factory->create($request->except('asset_ids'), $d['asset_ids'] ?? [], $request->user());
        return response()->json(['data' => ['id' => $e->id, 'code' => $e->code]], 201);
    }

    public function template(EmployeeImport $import): BinaryFileResponse
    {
        return response()->download($import->template(), 'UHR-employees-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function preview(Request $request, EmployeeImport $import): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $rows = $import->preview($request->file('file'));
        return response()->json(['rows' => $rows, 'ok' => count(array_filter($rows, fn ($r) => ! $r['errors'])), 'errors' => count(array_filter($rows, fn ($r) => $r['errors']))]);
    }

    public function import(Request $request, EmployeeImport $import): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:5120'], 'invite' => ['nullable', 'boolean']]);
        $made = $import->commit($request->file('file'), (bool) ($d['invite'] ?? true), $request->user());
        return response()->json(['created' => count($made), 'codes' => array_map(fn ($e) => $e->code, $made)], 201);
    }
}
