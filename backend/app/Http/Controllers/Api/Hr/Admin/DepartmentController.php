<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** M16 «الإدارات»: إضافة/تعديل/إيقاف، رئيس الإدارة، الملاك العددي المعتمد (يُقارن في F-31)، لون الإدارة في الهيكل، الترتيب */
class DepartmentController extends Controller
{
    public const COLORS = ['brand', 'brass', 'info', 'danger', 'ink-3'];

    public function index(): JsonResponse
    {
        $counts = Employee::current()->selectRaw('department_id, count(*) as n')->groupBy('department_id')->pluck('n', 'department_id');
        $rows = Department::with('head')->orderBy('sort')->orderBy('id')->get();
        return response()->json(['data' => $rows->map(fn (Department $d) => ['id' => $d->id, 'code' => $d->code, 'ar' => $d->name_ar, 'en' => $d->name_en, 'headcount' => (int) $d->headcount,
            'current' => (int) ($counts[$d->id] ?? 0), 'color' => $d->color, 'sort' => (int) $d->sort, 'active' => $d->active,
            'head' => $d->head ? ['id' => $d->head->id, 'code' => $d->head->code, 'ar' => $d->head->name, 'en' => $d->head->name_en] : null])->values(),
            'colors' => self::COLORS,
            'employees' => Employee::current()->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'department_id'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'department_id' => $e->department_id])]);
    }

    public function store(Request $request): JsonResponse
    {
        Department::create($this->valid($request));
        return $this->index();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = Department::findOrFail($id);
        $v = $this->valid($request, $d);
        if ($d->active && ! $v['active'] && Employee::current()->where('department_id', $d->id)->exists())
            throw new ApiException(L::t('لا يمكن إيقاف إدارة فيها موظفون حاليون — انقلهم أولاً.', 'A department with current employees cannot be deactivated — move them first.'), 'dept_in_use', 409);
        $d->update($v);
        return $this->index();
    }

    private function valid(Request $request, ?Department $d = null): array
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,19}$/', Rule::unique('departments', 'code')->where('company_id', \App\Support\Tenant::id())->ignore($d?->id)],
            'ar' => ['required', 'string', 'max:120'], 'en' => ['required', 'string', 'max:120'], 'headcount' => ['required', 'integer', 'between:0,10000'],
            'color' => ['required', Rule::in(self::COLORS)], 'sort' => ['nullable', 'integer', 'between:0,999'], 'active' => ['required', 'boolean'],
            'head_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', \App\Support\Tenant::id())],
        ], [], ['code' => L::t('الرمز', 'Code'), 'ar' => L::t('الاسم بالعربية', 'Arabic name'), 'en' => L::t('الاسم بالإنكليزية', 'English name'), 'headcount' => L::t('الملاك العددي', 'Approved headcount')]);
        return ['code' => $v['code'], 'name_ar' => trim($v['ar']), 'name_en' => trim($v['en']), 'headcount' => (int) $v['headcount'], 'color' => $v['color'],
            'sort' => (int) ($v['sort'] ?? $d?->sort ?? Department::max('sort') + 1), 'active' => (bool) $v['active'], 'head_id' => $v['head_id'] ?? null];
    }
}
