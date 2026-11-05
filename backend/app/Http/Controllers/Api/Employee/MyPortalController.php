<?php

namespace App\Http\Controllers\Api\Employee;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\People\Portal;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** بوابة الموظف M14: الرئيسية، التسجيل من الجوال، فريقي، ملفي الشخصي */
class MyPortalController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function home(Request $request): JsonResponse { return response()->json($this->portal->home($this->me($request))); }

    public function checkIn(Request $request): JsonResponse
    {
        $d = $request->validate(['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180']]);
        $r = $this->portal->checkIn($this->me($request), isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null);
        return response()->json(['result' => $r] + $this->portal->home($this->me($request)));
    }

    public function team(Request $request): JsonResponse { return response()->json($this->portal->team($this->me($request))); }

    /** ملاحظتا الاختبار 26 و27: العطل الرسمية للشركة لأي سنة — الأيام المتتالية بنفس الاسم تُجمع في فترة واحدة */
    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? now()->year);
        $groups = [];
        foreach (\App\Models\Holiday::whereYear('date', $year)->orderBy('date')->get() as $h) {
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['ar'] === $h->name_ar && \Illuminate\Support\Carbon::parse($groups[$last]['to'])->addDay()->isSameDay($h->date)) {
                $groups[$last]['to'] = $h->date->toDateString(); $groups[$last]['days']++; continue;
            }
            $groups[] = ['from' => $h->date->toDateString(), 'to' => $h->date->toDateString(), 'days' => 1, 'ar' => $h->name_ar, 'en' => $h->name_en];
        }
        $today = now()->toDateString();
        foreach ($groups as &$g) $g['state'] = $g['to'] < $today ? 'past' : ($g['from'] <= $today ? 'now' : 'upcoming');
        unset($g);
        $years = \App\Models\Holiday::selectRaw('distinct extract(year from date)::int as y')->pluck('y')->push(now()->year)->unique()->sort()->values();
        return response()->json(['year' => $year, 'years' => $years, 'data' => $groups, 'total' => array_sum(array_column($groups, 'days')),
            'work_days' => \App\Support\Settings::schedule('work_days', $today, $request->user()->employee?->department_id)]);
    }

    public function profile(Request $request): JsonResponse { return response()->json($this->portal->profile($this->me($request))); }

    public function phone(Request $request): JsonResponse
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $this->portal->updatePhone($this->me($request), $d['phone'], $request->user());
        return $this->profile($request);
    }

    private function me(Request $request): Employee
    {
        $id = $request->user()->employee_id;
        if (! $id) throw new ApiException(L::t('حسابك غير مرتبط بملف موظف.', 'Your account is not linked to an employee file.'), 'no_employee', 403);
        return Employee::findOrFail($id);
    }
}
