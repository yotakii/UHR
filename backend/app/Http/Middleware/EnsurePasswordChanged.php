<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** كلمة مرور مؤقتة: لا وصول لأي بيانات قبل تغييرها (SRS §4.1) */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->must_change_password) {
            return response()->json(['message' => \App\Support\L::t('يجب تغيير كلمة المرور المؤقتة أولاً.', 'You must change your temporary password first.'), 'code' => 'password_change_required'], 403);
        }
        return $next($request);
    }
}
