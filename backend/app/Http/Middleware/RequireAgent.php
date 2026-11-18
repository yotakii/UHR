<?php

namespace App\Http\Middleware;

use App\Models\PcAgent;
use App\Support\L;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/** وكيل جهاز الموظف (D-15): المفتاح في X-Agent-Key (sha256 في القاعدة)، والطلب يجري داخل شركة الوكيل */
class RequireAgent
{
    public function handle(Request $request, Closure $next)
    {
        $key = (string) $request->header('X-Agent-Key');
        $a = strlen($key) >= 32 ? PcAgent::allCompanies()->with('company')->where('key_hash', hash('sha256', $key))->first() : null;
        if (! $a || ! $a->active || $a->company?->status !== 'active') {
            return response()->json(['message' => L::t('الوكيل غير مسجّل أو موقوف — راجع الموارد البشرية.', 'This agent is not registered or was disabled — contact HR.'), 'code' => 'agent_unknown'], 401);
        }
        $request->attributes->set('agent', $a);
        return Tenant::run($a->company, fn () => $next($request));
    }
}
