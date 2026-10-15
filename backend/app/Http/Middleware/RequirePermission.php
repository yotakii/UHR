<?php

namespace App\Http\Middleware;

use App\Support\L;
use App\Support\Perm;
use Closure;
use Illuminate\Http\Request;

/** perm:jobs,create — صلاحية الوحدة والإجراء من مصفوفة الشركة (SRS §10.2) */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'view')
    {
        abort_unless(Perm::can($request->user(), $module, $action), 403, L::t('لا تملك صلاحية هذا الإجراء.', 'You are not allowed to do this.'));
        return $next($request);
    }
}
