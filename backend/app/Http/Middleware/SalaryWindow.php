<?php

namespace App\Http\Middleware;

use App\Services\Security\SalaryVault;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يرسل الثواني المتبقية من نافذة الرواتب بعد كل طلب مدّدها (SRS §4.4 — الفتح يتجدد مع الاستخدام) ليتحدث العدّاد في الهيدر */
class SalaryWindow
{
    public function handle(Request $request, Closure $next): Response
    {
        SalaryVault::$window = null;
        $res = $next($request);
        if (SalaryVault::$window !== null) $res->headers->set('X-Salary-Remaining', (string) SalaryVault::$window);
        return $res;
    }
}
