<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequestRow;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Attendance\RecentDays;
use App\Services\Leave\LeaveService;
use App\Services\Workflow\Workflow;
use App\Support\L;
use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «إجازاتي وطلباتي» و«حضوري» لأي حساب مرتبط بملف موظف (M14 — HR.01-01-F-03، D-18) */
class MyRequestsController extends Controller
{
    public function __construct(private LeaveService $leaves, private Workflow $flow) {}

    public function index(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $rows = LeaveRequest::with(RequestRow::WITH)->where('employee_id', $e->id)->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        return response()->json([
            'balance' => $this->leaves->balance($e),
            'types' => collect(\App\Support\Settings::policy('leave_types'))->map(fn ($t) => array_intersect_key($t, array_flip(['key', 'ar', 'en', 'unit', 'max_days', 'notice', 'doc', 'paid', 'balance'])))->values(),
            'rules' => array_intersect_key(LeaveService::rules(), array_flip(['notice', 'hourly_notice_days', 'substitute_required', 'hourly_max_per_day', 'justify_hours', 'emergency_report_hours'])),
            'requests' => $rows->map(fn ($l) => RequestRow::leave($l, $request->user())),
            'colleagues' => Employee::current()->where('id', '!=', $e->id)->with('department')->orderByRaw('case when department_id = ? then 0 else 1 end', [$e->department_id])->orderBy('name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'ar' => $c->name, 'en' => $c->name_en, 'same_dept' => $c->department_id === $e->department_id]),
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        return response()->json($this->leaves->check($this->me($request), $request->all(), $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['type' => ['required', 'string'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'hours' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'], 'substitute_id' => ['nullable', 'integer'], 'file' => ['nullable', 'file']]);
        $l = $this->leaves->submit($this->me($request), $request->except('file'), $request->user(), $request->file('file'));
        return response()->json(['data' => RequestRow::leave($l->load(RequestRow::WITH), $request->user())], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $l = $this->mine($request, $id);
        $this->flow->cancel($l, $request->user());
        return response()->json(['data' => RequestRow::leave($l->fresh(RequestRow::WITH), $request->user())]);
    }

    public function attach(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $l = $this->leaves->attach($this->mine($request, $id), $request->file('file'));
        return response()->json(['data' => RequestRow::leave($l->fresh(RequestRow::WITH), $request->user())]);
    }

    /** حضوري: آخر 14 يوم عمل + الأيام بحاجة لتبرير ضمن المهلة */
    public function attendance(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $m = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        $out = ['days' => RecentDays::rows($e), 'issues' => $this->leaves->issues($e), 'justify_hours' => (int) LeaveService::rules()['justify_hours']];
        // ملاحظة الاختبار 6: سجل أي شهر سابق منذ المباشرة، مع ملخصه
        $first = $e->hire_date?->format('Y-m') ?? now()->format('Y-m');
        $months = [];
        for ($d = now()->startOfMonth(); $d->format('Y-m') >= $first && count($months) < 24; $d->subMonth()) $months[] = $d->format('Y-m');
        $out['months'] = $months;
        if ($m) {
            if ($m > now()->format('Y-m') || $m < $first) throw \App\Exceptions\ApiException::field('month', L::t('الشهر خارج فترة خدمتك.', 'The month is outside your service.'), 'month_range');
            $from = $m.'-01'; $to = min(\Illuminate\Support\Carbon::parse($from)->endOfMonth()->toDateString(), now()->toDateString());
            $days = \App\Models\AttendanceDay::where('employee_id', $e->id)->whereBetween('date', [$from, $to])->get()->keyBy(fn ($d) => $d->date->toDateString());
            $leave = \App\Services\Attendance\Attendance::onLeave(collect([$e->id]), $from, $to)[$e->id] ?? [];
            $rows = [];
            for ($d = \Illuminate\Support\Carbon::parse($from.' 12:00:00'); $d->toDateString() <= $to; $d->addDay()) {
                $date = $d->toDateString();
                $rows[] = \App\Services\Attendance\Attendance::row($e, $date, $days->get($date), isset($leave[$date]));
            }
            $c = collect($rows);
            $out['month'] = ['month' => $m, 'rows' => array_reverse($rows), 'summary' => ['present' => $c->whereNotNull('in')->count(), 'absent' => $c->where('status', 'absent')->count(),
                'late_days' => $c->where('status', 'late')->count(), 'late' => $c->where('status', 'late')->sum('late'), 'leave' => $c->where('status', 'leave')->count(), 'incomplete' => $c->where('status', 'incomplete')->count(), 'ot' => $c->sum('ot')]];
        }
        return response()->json($out);
    }

    public function justify(Request $request): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'file' => ['nullable', 'file']]);
        $l = $this->leaves->justify($this->me($request), $d['date'], trim($d['reason']), $request->user(), $request->file('file'));
        return response()->json(['data' => RequestRow::leave($l->load(RequestRow::WITH), $request->user())], 201);
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        if (! $id) throw new ApiException(L::t('حسابك غير مرتبط بملف موظف.', 'Your account is not linked to an employee file.'), 'no_employee', 403);
        return Employee::findOrFail($id);
    }

    private function mine(Request $request, int $id): LeaveRequest
    {
        return LeaveRequest::with(RequestRow::WITH)->where('employee_id', $this->me($request)->id)->findOrFail($id);
    }
}
