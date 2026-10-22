<?php

namespace App\Http\Controllers\Api\Employee;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\ViolationType;
use App\Services\Discipline\Complaints;
use App\Services\Discipline\Discipline;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * بوابة الموظف (M17): «إنذاراتي» — الحالات بحقه، كتابة أقواله، الإقرار بالاستلام بتوقيعه، طباعة F-01؛ وشكاواه F-02.
 * والمدير: فتح حالة لفريقه ومتابعة حالاتهم (بلا الأقوال والتحقيق).
 */
class MyDisciplineController extends Controller
{
    private const WITH = ['employee.department', 'type', 'opener.employee', 'decider.employee'];

    public function __construct(private Discipline $discipline, private Complaints $complaints) {}

    public function index(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $u = $request->user();
        $cases = DisciplineCase::with(self::WITH)->where('employee_id', $e->id)->latest('id')->limit(100)->get();
        $team = Employee::current()->where('manager_id', $e->id)->orderBy('name')->get();
        $isMgr = $team->isNotEmpty() && Perm::can($u, 'discipline', 'create');
        // محضر التحقيق (الشهود والملاحظات) للموارد البشرية — يرى الموظف تاريخه فقط
        return response()->json(['data' => $cases->map(fn ($c) => ['investigation' => $c->investigation ? ['at' => $c->investigation['at'] ?? null] : null] + Discipline::row($c, $u, true)),
            'complaints' => Complaint::with('responder.employee')->where('employee_id', $e->id)->latest('id')->limit(50)->get()->map(fn ($c) => Complaints::row($c, false)),
            'team' => $isMgr ? $team->map(fn ($x) => ['id' => $x->id, 'code' => $x->code, 'ar' => $x->name, 'en' => $x->name_en])->values() : [],
            'team_cases' => $isMgr ? DisciplineCase::with(self::WITH)->whereIn('employee_id', $team->pluck('id'))->latest('id')->limit(100)->get()->map(fn ($c) => Discipline::row($c, $u)) : [],
            'types' => $isMgr ? ViolationType::where('active', true)->orderByRaw("case \"group\" when 'time' then 0 when 'work' then 1 else 2 end")->orderBy('number')->get()->map(fn ($t) => Discipline::typeRow($t)) : [],
            'company' => ['ar' => Settings::company('name_ar'), 'en' => Settings::company('name_en')],
            'me' => ['name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'code' => $e->code,
                'dept' => $e->department ? ['ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null]]);
    }

    public function defend(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['defense' => ['required', 'string', 'min:5', 'max:4000']]);
        $this->discipline->defend($this->case($request, $id), $request->user(), $d['defense']);
        return $this->index($request);
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['signature' => ['required', 'string']]);
        $this->discipline->acknowledge($this->case($request, $id), $request->user(), $d['signature']);
        return $this->index($request);
    }

    /** المدير يفتح حالة لأحد فريقه */
    public function open(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'violation_type_id' => ['required', 'integer'], 'occurred_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'min:5', 'max:2000']]);
        $me = $this->me($request);
        $e = Employee::current()->where('manager_id', $me->id)->findOrFail($d['employee_id']);
        $this->discipline->open($request->user()->loadMissing('roles'), $e, $d);
        return $this->index($request);
    }

    public function complain(Request $request): JsonResponse
    {
        $d = $request->validate(['subject' => ['required', 'string', 'min:3', 'max:190'], 'body' => ['required', 'string', 'min:10', 'max:4000'], 'against' => ['nullable', 'string', 'max:190']],
            [], ['subject' => L::t('الموضوع', 'Subject'), 'body' => L::t('تفاصيل الشكوى', 'Details'), 'against' => L::t('بحق', 'Against')]);
        $this->complaints->submit($this->me($request), $d);
        return $this->index($request);
    }

    private function case(Request $request, int $id): DisciplineCase
    {
        return DisciplineCase::with(self::WITH)->where('employee_id', $this->me($request)->id)->findOrFail($id);
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        if (! $id) throw new ApiException(L::t('حسابك غير مرتبط بملف موظف.', 'Your account is not linked to an employee file.'), 'no_employee', 403);
        return Employee::with('department')->findOrFail($id);
    }
}
