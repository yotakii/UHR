<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * الشركة الحالية = شركة المستخدم المسجّل (موظف/HR). المتقدم ومدير المنصة والزائر بلا شركة،
 * والطلبات العامة تحدد الشركة من الشاغر أو slug صراحةً (SRS §10.3).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        return Tenant::run($request->user()?->company_id, fn () => $next($request));
    }
}
