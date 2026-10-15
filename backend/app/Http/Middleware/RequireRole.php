<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** role:hr_director,recruiter — أي دور من القائمة (المصفوفة الكاملة في لوحة الصلاحيات لاحقاً — SRS §10.2) */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        abort_unless($request->user()?->hasRole(...$roles), 403, \App\Support\L::t('لا تملك صلاحية هذا الإجراء.', 'You are not allowed to do this.'));
        return $next($request);
    }
}
