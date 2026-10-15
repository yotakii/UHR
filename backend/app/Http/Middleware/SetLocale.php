<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale(str_starts_with(strtolower((string) $request->header('Accept-Language')), 'en') ? 'en' : 'ar');
        return $next($request);
    }
}
