<?php

namespace App\Http\Middleware;

use App\Support\L;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * الشركة الحالية = شركة المستخدم المسجّل (موظف/HR). المتقدم ومدير المنصة والزائر بلا شركة،
 * والطلبات العامة تحدد الشركة من الشاغر أو slug صراحةً (SRS §10.3).
 * الحساب الموقوف (انتهت خدمته، عُطّل، أو عُلّقت شركته) تنتهي جلسته عند أول طلب (M11، D-14).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if ($u && $u->loadMissing(['employee', 'company'])->blocked()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) { $request->session()->invalidate(); $request->session()->regenerateToken(); }
            return response()->json(['message' => L::t('انتهت جلستك — هذا الحساب موقوف.', 'Your session has ended — this account is disabled.'), 'code' => 'blocked'], 401);
        }
        return Tenant::run($u?->company_id, fn () => $next($request));
    }
}
