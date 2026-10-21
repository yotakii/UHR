<?php

namespace App\Http\Controllers\Api\Employee;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RequestRow;
use App\Models\Employee;
use App\Models\MonthSheetRow;
use App\Models\ServiceRequest;
use App\Services\Payroll\MonthCalc;
use App\Services\Payroll\Payroll;
use App\Services\People\ServiceDesk;
use App\Services\Workflow\Workflow;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «خدماتي» و«كشفي الشهري» لأي حساب مرتبط بموظف (M14، D-16، D-01) */
class MyServicesController extends Controller
{
    public function __construct(private ServiceDesk $desk) {}

    public function index(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $rows = ServiceRequest::with(RequestRow::SERVICE_WITH)->where('employee_id', $e->id)->latest('id')->limit(50)->get();
        return response()->json(['data' => $rows->map(fn ($x) => RequestRow::service($x, $request->user())), 'advance' => Payroll::advanceAvailable($e),
            'kinds' => collect(ServiceRequest::KINDS)->map(fn ($k, $key) => ['key' => $key, 'ar' => $k[0], 'en' => $k[1]])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $s = $this->desk->submit($this->me($request), $request->all(), $request->user());
        return response()->json(['data' => RequestRow::service($s->load(RequestRow::SERVICE_WITH), $request->user())], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $s = ServiceRequest::with(RequestRow::SERVICE_WITH)->where('employee_id', $this->me($request)->id)->findOrFail($id);
        app(Workflow::class)->cancel($s, $request->user());
        return response()->json(['data' => RequestRow::service($s->fresh(RequestRow::SERVICE_WITH), $request->user())]);
    }

    public function certificate(Request $request, int $id): JsonResponse
    {
        $s = ServiceRequest::with(['employee', 'issuer.employee'])->where('employee_id', $this->me($request)->id)->whereNotNull('ref_no')->findOrFail($id);
        return response()->json(['data' => ServiceDesk::certificate($s)]);
    }

    /** كشوفي: المغلقة فقط + تقدير الشهر الجاري حتى أمس */
    public function payslips(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $closed = MonthSheetRow::with('sheet')->where('employee_id', $e->id)->whereHas('sheet', fn ($q) => $q->where('status', 'closed'))->get()
            ->sortByDesc(fn ($r) => $r->sheet->month)->values()->map(fn ($r) => ['month' => $r->sheet->month, 'status' => 'closed', 'data' => $r->data, 'money' => $r->money, 'closed_at' => $r->sheet->closed_at?->toIso8601String()]);
        $month = Payroll::currentMonth();
        $live = MonthCalc::compute($e, $month);
        return response()->json(['data' => $closed, 'current' => ['month' => $month, 'status' => 'estimate'] + $live, 'pay_day' => (int) (\App\Support\Settings::policy('payslip')['pay_day'] ?? 28)]);
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        if (! $id) throw new ApiException(L::t('حسابك غير مرتبط بملف موظف.', 'Your account is not linked to an employee file.'), 'no_employee', 403);
        return Employee::findOrFail($id);
    }
}
