<?php

namespace App\Http\Controllers\Api\Employee;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExitCase;
use App\Models\Employee;
use App\Models\Resignation;
use App\Services\Exit\ExitFlow;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * بوابة الموظف (M11): طلب الاستقالة F-02 مع الاستبيان ومتابعته، الرجوع عنها، جدول تسليم المهام F-05 وتعهده،
 * وإقرار استلام المهام لمن سُمّي مستلماً.
 */
class MyExitController extends Controller
{
    public function __construct(private ExitFlow $flow) {}

    public function index(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $u = $request->user();
        $mine = Resignation::with(ExitCase::WITH)->where('employee_id', $e->id)->where('kind', 'resignation')->latest('id')->first();
        // طلبات الخروج التي سُمّيت فيها مستلماً للمهام ولم توقّع بعد
        $receiving = Resignation::with(ExitCase::WITH)->where('status', 'clearance')->whereRaw("(handover->>'receiver_id')::int = ?", [$e->id])->get()
            ->filter(fn ($r) => ! empty($r->handover['employee']))->values();
        // المدير: طلبات خروج فريقه (توصيته من صندوق الاعتماد، وتوقيع جهته في F-05 وF-07 من هنا)
        $team = Resignation::with(ExitCase::WITH)->whereIn('status', Resignation::OPEN)->whereIn('employee_id', Employee::where('manager_id', $e->id)->select('id'))->latest('id')->get();
        return response()->json(['data' => $mine ? ExitCase::full($mine, $u) : null, 'receiving' => $receiving->map(fn ($r) => ExitCase::full($r, $u))->values(),
            'team' => $team->map(fn ($r) => ExitCase::full($r, $u))->values(),
            'lists' => ['reasons' => ExitFlow::lists()['reasons'], 'questions' => ExitFlow::lists()['questions']], 'policy' => Settings::policy('exit'),
            'colleagues' => Employee::current()->where('id', '!=', $e->id)->where('status', '!=', 'resigning')->orderBy('name')->get(['id', 'code', 'name', 'name_en', 'department_id'])
                ->map(fn ($x) => ['id' => $x->id, 'code' => $x->code, 'ar' => $x->name, 'en' => $x->name_en, 'same_dept' => $x->department_id === $e->department_id])->values(),
            'company' => ['ar' => Settings::company('name_ar'), 'en' => Settings::company('name_en')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->flow->submit($this->me($request), $request->all(), $request->user());
        return $this->index($request);
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['text' => ['required', 'string', 'min:10', 'max:2000']], [], ['text' => L::t('نص طلب الرجوع', 'Withdrawal text')]);
        $this->flow->withdraw($this->mine($request, $id), $request->user(), $d['text']);
        return $this->index($request);
    }

    public function handover(Request $request, int $id): JsonResponse
    {
        $this->flow->saveHandover($this->mine($request, $id), $request->user(), $request->all());
        return $this->index($request);
    }

    /** as=employee (تعهد المسلِّم)، receiver (إقرار المستلم)، manager (مصادقة المدير) — التحقق في ExitFlow */
    public function sign(Request $request, int $id): JsonResponse
    {
        $as = $request->validate(['as' => ['required', 'in:employee,receiver,manager']])['as'];
        $this->flow->signHandover($this->related($request, $id), $request->user()->loadMissing('roles'), $as);
        return $this->index($request);
    }

    /** جهة «إدارة الموظف» في F-07 للمدير المباشر */
    public function markItem(Request $request, int $id, string $section, string $item): JsonResponse
    {
        $this->flow->markItem($this->related($request, $id), $request->user()->loadMissing('roles'), $section, $item, $request->all());
        return $this->index($request);
    }

    public function signSection(Request $request, int $id, string $section): JsonResponse
    {
        $this->flow->signSection($this->related($request, $id), $request->user()->loadMissing('roles'), $section);
        return $this->index($request);
    }

    /** طلب خروج يخصّني: طلبي، أو أنا مستلم مهامه، أو مديره المباشر */
    private function related(Request $request, int $id): Resignation
    {
        $e = $this->me($request);
        $r = Resignation::with('employee')->findOrFail($id);
        if ($r->employee_id !== $e->id && (int) ($r->handover['receiver_id'] ?? 0) !== $e->id && $r->employee?->manager_id !== $e->id) abort(404);
        return $r;
    }

    private function mine(Request $request, int $id): Resignation
    {
        return Resignation::where('employee_id', $this->me($request)->id)->findOrFail($id);
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        if (! $id) throw new ApiException(L::t('حسابك غير مرتبط بملف موظف.', 'Your account is not linked to an employee file.'), 'no_employee', 403);
        return Employee::findOrFail($id);
    }
}
