<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\ShiftAssignment;
use App\Models\WorkSchedule;
use App\Services\Attendance\DayCalc;
use App\Services\Attendance\WorkSchedules;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** جداول الدوام والورديات والقوالب الموسمية، جدول المناوبات الأسبوعي، والفروع ومناطقها الزمنية (ملاحظات 24، 76، 80) */
class ScheduleController extends Controller
{
    public function __construct(private WorkSchedules $svc) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $count = Employee::current()->whereNotNull('work_schedule_id')->selectRaw('work_schedule_id, count(*) n')->groupBy('work_schedule_id')->pluck('n', 'work_schedule_id');
        $today = now()->toDateString();
        $assign = ShiftAssignment::with(['employee:id,code,name,name_en', 'schedule:id,name_ar,name_en,shifts'])->where(fn ($q) => $q->whereNull('to')->orWhere('to', '>=', $today))
            ->whereIn('employee_id', Directory::scoped($u, 'attendance')->select('id'))->orderBy('from')->limit(300)->get();
        return response()->json([
            'data' => WorkSchedule::orderBy('id')->get()->map(fn ($s) => self::row($s) + ['employees' => (int) ($count[$s->id] ?? 0)]),
            'assignments' => $assign->map(fn ($a) => ['id' => $a->id, 'employee' => ['id' => $a->employee->id, 'code' => $a->employee->code, 'ar' => $a->employee->name, 'en' => $a->employee->name_en],
                'schedule' => ['id' => $a->schedule->id, 'ar' => $a->schedule->name_ar, 'en' => $a->schedule->name_en ?: $a->schedule->name_ar],
                'shift' => collect($a->schedule->shifts)->firstWhere('key', $a->shift_key), 'from' => $a->from->toDateString(), 'to' => $a->to?->toDateString(), 'note' => $a->note]),
            'employees' => Directory::scoped($u, 'attendance')->current()->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'department_id', 'work_schedule_id'])
                ->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'department_id' => $e->department_id, 'work_schedule_id' => $e->work_schedule_id]),
            'departments' => Department::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'can' => ['edit' => Perm::can($u, 'policies', 'edit')],
        ]);
    }

    public static function row(WorkSchedule $s): array
    {
        return ['id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'kind' => $s->kind, 'shifts' => $s->shifts, 'work_days' => $s->work_days,
            'season_from' => $s->season_from?->toDateString(), 'season_to' => $s->season_to?->toDateString(), 'color' => $s->color, 'active' => $s->active];
    }

    public function store(Request $request): JsonResponse
    {
        $s = $this->svc->save(null, $request->all());
        return response()->json(['data' => self::row($s)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => self::row($this->svc->save(WorkSchedule::findOrFail($id), $request->all()))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $s = WorkSchedule::findOrFail($id);
        if (Employee::where('work_schedule_id', $s->id)->exists() || ShiftAssignment::where('work_schedule_id', $s->id)->exists()) {
            throw new ApiException(L::t('الجدول مسند لموظفين — أوقفه بدل حذفه، أو انقلهم لجدول آخر أولاً.', 'The schedule is assigned — deactivate it instead, or move the employees first.'), 'schedule_in_use', 409);
        }
        $s->delete();
        WorkSchedules::forget();
        return response()->json(['ok' => true]);
    }

    public function assign(Request $request): JsonResponse
    {
        $ids = (array) $request->input('employee_ids', []);
        $allowed = Directory::scoped($request->user(), 'attendance')->whereIn('id', $ids)->pluck('id')->all();
        if (count($allowed) !== count(array_unique($ids))) throw new ApiException(L::t('بعض الموظفين خارج نطاقك.', 'Some employees are outside your scope.'), 'forbidden', 403);
        $n = $this->svc->assign($request->all(), $request->user());
        Audit::record('schedule.assigned', null, null, $request->only(['employee_ids', 'work_schedule_id', 'mode', 'shift_key', 'from', 'to']) + ['count' => $n]);
        return response()->json(['count' => $n] + $this->index($request)->getData(true));
    }

    public function unassign(Request $request, int $id): JsonResponse
    {
        $a = ShiftAssignment::whereIn('employee_id', Directory::scoped($request->user(), 'attendance')->select('id'))->findOrFail($id);
        $a->delete();
        WorkSchedules::forget();
        return $this->index($request);
    }

    /** المناوبات: الموظفون × أيام الأسبوع — الوردية السارية لكل يوم (أو راحة/عطلة) */
    public function roster(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'dept' => ['nullable', 'integer'], 'days' => ['nullable', 'integer', 'between:1,14']]);
        $from = Carbon::parse($f['from'] ?? now()->toDateString());
        $days = collect(range(0, ($f['days'] ?? 7) - 1))->map(fn ($i) => $from->copy()->addDays($i)->toDateString());
        $q = Directory::scoped($request->user(), 'attendance')->current()->orderBy('code');
        if ($f['dept'] ?? null) $q->where('department_id', $f['dept']);
        $rows = $q->limit(200)->get(['id', 'code', 'name', 'name_en', 'department_id', 'location_id', 'work_schedule_id'])->map(function (Employee $e) use ($days) {
            return ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'days' => $days->map(function ($d) use ($e) {
                $r = WorkSchedules::resolve($e, $d);
                $off = DayCalc::holiday($d) ? 'holiday' : (DayCalc::weekend($d, $e) ? 'weekend' : null);
                return ['date' => $d, 'off' => $off, 'start' => $r['shift']['start'], 'end' => $r['shift']['end'], 'source' => $r['source'],
                    'schedule' => $r['schedule'] ? ['id' => $r['schedule']->id, 'ar' => $r['schedule']->name_ar, 'color' => $r['schedule']->color] : null, 'shift' => $r['shift_key'], 'tz' => $r['tz']];
            })->values()];
        });
        return response()->json(['days' => $days->values(), 'data' => $rows->values()]);
    }

    /** الفروع: المدن المشتركة (للقراءة) + فروع الشركة، ولكل فرع منطقة زمنية (ملاحظة 80) */
    public function branches(Request $request): JsonResponse
    {
        $count = Employee::current()->selectRaw('location_id, count(*) n')->groupBy('location_id')->pluck('n', 'location_id');
        return response()->json(['data' => Location::orderByRaw('company_id is null desc')->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'ar' => $l->name_ar, 'en' => $l->name_en,
            'timezone' => $l->timezone, 'active' => $l->active, 'own' => $l->company_id !== null, 'employees' => (int) ($count[$l->id] ?? 0)]),
            'timezones' => self::ZONES, 'can' => ['edit' => Perm::can($request->user(), 'settings', 'edit')]]);
    }

    public const ZONES = ['Asia/Damascus', 'Asia/Beirut', 'Asia/Amman', 'Asia/Baghdad', 'Asia/Riyadh', 'Asia/Kuwait', 'Asia/Qatar', 'Asia/Bahrain', 'Asia/Dubai', 'Asia/Muscat',
        'Africa/Cairo', 'Europe/Istanbul', 'Europe/Berlin', 'Europe/London', 'Europe/Paris', 'Asia/Tehran', 'Asia/Kolkata', 'America/New_York', 'UTC'];

    public function saveBranch(Request $request, ?int $id = null): JsonResponse
    {
        $l = $id ? Location::whereNotNull('company_id')->findOrFail($id) : new Location;
        $d = $request->validate(['code' => [$id ? 'sometimes' : 'required', 'string', 'regex:/^[a-z][a-z0-9_-]{1,19}$/',
                Rule::unique('locations', 'code')->where(fn ($q) => $q->where('company_id', \App\Support\Tenant::id())->orWhereNull('company_id'))->ignore($id)],
            'name_ar' => ['required', 'string', 'max:100'], 'name_en' => ['required', 'string', 'max:100'], 'timezone' => ['required', Rule::in(self::ZONES)], 'active' => ['boolean']],
            ['code.unique' => L::t('الرمز مستخدم لفرع أو مدينة أخرى.', 'This code is used by another branch or city.'), 'code.regex' => L::t('الرمز بأحرف إنكليزية صغيرة (مثل dubai).', 'Lowercase letters (e.g. dubai).')]);
        if ($id && ($d['active'] ?? true) === false && Employee::current()->where('location_id', $l->id)->exists()) throw new ApiException(L::t('لا يمكن إيقاف فرع فيه موظفون.', 'A branch with employees cannot be deactivated.'), 'branch_in_use', 409);
        $l->fill(($id ? collect($d)->except('code')->all() : $d) + ($id ? [] : ['company_id' => \App\Support\Tenant::id()]))->save();
        WorkSchedules::forget();
        return $this->branches($request);
    }
}
