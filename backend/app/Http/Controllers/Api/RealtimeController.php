<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إعداد الاتصال اللحظي للواجهة: المفتاح العام فقط (نفس الأصل، المسار /app) — بلا اتصال إن كان البث معطلاً */
class RealtimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $u = $request->user();
        $key = config('broadcasting.connections.reverb.key');
        // القنوات التي يحق للمستخدم الاشتراك بها (نفس شروط routes/channels.php)
        $channels = ["user.{$u->id}"];
        if ($u->company_id && $u->portal() === 'hr') $channels[] = "company.{$u->company_id}";
        return response()->json(['enabled' => config('broadcasting.default') === 'reverb' && (bool) $key, 'key' => $key ?: null, 'channels' => $channels]);
    }
}
