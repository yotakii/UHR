<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\HrDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** لوحة المتابعة (M15) — الأقسام حسب صلاحيات المستخدم */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => (new HrDashboard($request->user()))->build()]);
    }
}
