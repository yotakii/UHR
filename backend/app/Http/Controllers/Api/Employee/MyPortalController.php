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
