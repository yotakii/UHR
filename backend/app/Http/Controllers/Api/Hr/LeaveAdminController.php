<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequestRow;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\ServiceRequest;
use App\Services\People\Directory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** M09 — الإجازات والتبريرات والتكليف بالإضافي لنظام الشركة (Leaves في الديمو) بنطاق صلاحية «طلبات الإجازة» */
class LeaveAdminController extends Controller
{
    public const TABS = ['pending', 'justify', 'overtime', 'services', 'all'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['tab' => ['nullable', 'in:'.implode(',', self::TABS)], 'q' => ['nullable', 'string', 'max:100']]);
        $u = $request->user()->loadMissing('roles');
        $tab = $f['tab'] ?? 'pending';
        $ids = Directory::scoped($u, 'leaves')->pluck('id');
        $like = ($q = trim($f['q'] ?? '')) ? '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%' : null;
        $who = fn ($w) => $like ? $w->whereHas('employee', fn ($e) => $e->where('name', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('code', 'ilike', $like)) : $w;
        if ($tab === 'services') {
            $rows = $who(ServiceRequest::with(RequestRow::SERVICE_WITH)->whereIn('employee_id', $ids))->orderByRaw("case when status like 'pending%' then 0 else 1 end")->latest('id')->limit(200)->get()->map(fn ($x) => RequestRow::service($x, $u));
        } elseif ($tab === 'overtime') {
            $rows = $who(OvertimeRequest::with(['employee', 'requester.employee', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee'])->whereIn('employee_id', $ids))->latest('id')->limit(200)->get()->map(fn ($o) => RequestRow::overtime($o, $u));
        } else {
            $q = $who(LeaveRequest::with(RequestRow::WITH)->whereIn('employee_id', $ids));
            if ($tab === 'pending') $q->pending(); elseif ($tab === 'justify') $q->where('type', 'justify');
            $rows = $q->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get()->map(fn ($l) => RequestRow::leave($l, $u));
        }
        $count = fn ($q) => $q->whereIn('employee_id', $ids)->count();
        return response()->json(['data' => $rows->values(), 'reject_min' => (int) \App\Services\Leave\LeaveService::rules()['reject_min'], 'counts' => [
            'pending' => $count(LeaveRequest::pending()), 'justify' => $count(LeaveRequest::where('type', 'justify')->pending()), 'overtime' => $count(OvertimeRequest::whereIn('status', LeaveRequest::PENDING)), 'services' => $count(ServiceRequest::whereIn('status', LeaveRequest::PENDING)),
        ]]);
    }
}
