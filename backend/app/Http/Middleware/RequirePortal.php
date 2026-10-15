<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** portal:hr — مسارات لوحة الإدارة؛ portal:employee — بوابة الموظف؛ portal:candidate — بوابة المتقدم */
class RequirePortal
{
    public function handle(Request $request, Closure $next, string ...$portals)
    {
        $u = $request->user();
        abort_unless($u && in_array($u->portal(), $portals, true), 403, \App\Support\L::t('لا تملك صلاحية الوصول لهذه الصفحة.', 'You do not have access to this page.'));
        return $next($request);
    }
}
