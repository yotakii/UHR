<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Resources\HrEmployee;
use App\Models\Department;
use App\Models\Employee;
use App\Services\People\Directory;
use App\Services\People\EmployeeProfile;
use App\Support\Audit;
use App\Support\Perm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M07 — دليل الموظفين، الملف الوظيفي، الهيكل التنظيمي، وتصدير Excel (كلها بنطاق صلاحية «الموظفين») */
class DirectoryController extends Controller
{
    public const STATUS = ['current', 'onboarding', 'probation', 'active', 'leave', 'resigning', 'terminated', 'all'];

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $list = $this->filtered($request)->with(HrEmployee::WITH)->orderBy('code')->limit(5000)->get();
        $base = Directory::scoped($u);
        return response()->json([
            'data' => $list->map(fn ($e) => HrEmployee::row($e)),
            'current' => (clone $base)->current()->count(), 'departments' => Department::where('active', true)->count(),
            'depts' => Department::where('active', true)->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'color' => $d->color, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'can' => ['create' => Perm::can($u, 'employees', 'create'), 'export' => Perm::can($u, 'employees', 'export')],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $e = Directory::scoped($request->user())->with(HrEmployee::WITH)->findOrFail($id);
        return response()->json(['data' => HrEmployee::full($e, $request->user())]);
    }

    public function update(Request $request, int $id, EmployeeProfile $profile): JsonResponse
    {
        $e = Directory::scoped($request->user())->findOrFail($id);
        $profile->update($e, $request->all(), $request->user());
        return $this->show($request, $id);
    }

    public function card(Request $request, int $id, EmployeeProfile $profile): JsonResponse
    {
        $uid = $request->validate(['uid' => ['nullable', 'string', 'max:60']])['uid'] ?? null;
        $profile->card(Directory::scoped($request->user())->findOrFail($id), $uid, $request->user());
        return $this->show($request, $id);
    }

    /** آخر بطاقة مُرّرت على جهاز/جسر للشركة بعد since (وقت الخادم عند فتح نافذة الربط — يُعاد في now) */
    public function lastCard(Request $request): JsonResponse
    {
        $since = $request->validate(['since' => ['nullable', 'date']])['since'] ?? null;
        $r = \App\Services\Attendance\Attendance::lastRead();
        return response()->json(['data' => $r && (! $since || \Illuminate\Support\Carbon::parse($r['at'])->gt(\Illuminate\Support\Carbon::parse($since))) ? $r : null, 'now' => now()->toIso8601String()]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $rows = $this->filtered($request)->with([...HrEmployee::WITH, 'manager'])->orderBy('code')->limit(10000)->get();
        $path = tempnam(sys_get_temp_dir(), 'emp').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('Employees')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $w->addRow(Row::fromValues(['الرقم', 'الاسم', 'الاسم بالإنكليزية', 'المسمى', 'الإدارة', 'المدينة', 'المدير المباشر', 'البريد', 'الجوال', 'تاريخ المباشرة', 'الحالة'], (new Style())->setFontBold()));
        $st = ['onboarding' => 'قيد المباشرة', 'probation' => 'فترة التجربة', 'active' => 'على رأس العمل', 'leave' => 'إجازة', 'resigning' => 'مستقيل — فترة إخطار', 'terminated' => 'منتهي الخدمة'];
        foreach ($rows as $e) $w->addRow(Row::fromValues([$e->code, $e->name, $e->name_en, $e->title_ar, $e->department->name_ar, $e->location->name_ar, $e->manager?->name ?? '',
            $e->email, (string) $e->phone, $e->hire_date?->toDateString(), $st[$e->status] ?? $e->status]));
        $w->close();
        Audit::record('employees.export', null, null, ['filters' => $request->only(['q', 'dept', 'status']), 'count' => $rows->count()]);
        return response()->download($path, 'UHR-employees-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** الهيكل التنظيمي: كل من على رأس العمل (الاسم والمسمى والإدارة والمدير) — «open» = يستطيع المستخدم فتح ملفه */
    public function org(Request $request): JsonResponse
    {
        $u = $request->user();
        $list = Employee::current()->with('department')->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'gender', 'title_ar', 'title_en', 'department_id', 'manager_id', 'status']);
        $open = Perm::can($u, 'employees') ? Directory::scoped($u)->pluck('id')->flip() : collect();
        return response()->json([
            'data' => $list->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
                'dept' => $e->department->code, 'manager_id' => $e->manager_id, 'open' => $open->has($e->id)]),
            'depts' => Department::where('active', true)->orderBy('sort')->get()->map(fn ($d) => ['code' => $d->code, 'color' => $d->color, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'chart' => \App\Support\Settings::merged('org_chart'),
            'can' => ['print' => Perm::can($u, 'org', 'print'), 'settings' => Perm::can($u, 'settings', 'edit')],
        ]);
    }

    /** تصدير الهيكل PDF (D-33): يُسجَّل ثم يطبع المتصفح الصفحات */
    public function orgExport(Request $request): JsonResponse
    {
        $v = $request->validate(['scope' => ['required', 'in:company,department,branch'], 'dept' => ['nullable', 'string', 'max:20'], 'root' => ['nullable', 'integer'],
            'levels' => ['required', 'integer', 'between:2,8'], 'mode' => ['required', 'in:names_titles,titles'], 'pages' => ['nullable', 'integer', 'min:0']]);
        Audit::record('org.exported', null, null, $v);
        return response()->json(['ok' => true]);
    }

    public function orgSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['mode' => ['required', 'in:names_titles,titles'], 'levels' => ['required', 'integer', 'between:2,8']]);
        $before = \App\Support\Settings::merged('org_chart');
        \App\Support\Settings::put('org_chart', ['mode' => $v['mode'], 'levels' => (int) $v['levels']]);
        Audit::record('settings.org_chart', null, $before, \App\Support\Settings::merged('org_chart'));
        return response()->json(['data' => \App\Support\Settings::merged('org_chart')]);
    }

    private function filtered(Request $request): Builder
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'dept' => ['nullable', 'integer'], 'status' => ['nullable', 'in:'.implode(',', self::STATUS)]]);
        $q = Directory::scoped($request->user());
        $st = $f['status'] ?? 'current';
        if ($st === 'current') $q->current(); elseif ($st !== 'all') $q->where('status', $st);
        if ($f['dept'] ?? null) $q->where('department_id', $f['dept']);
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }
        return $q;
    }
}
