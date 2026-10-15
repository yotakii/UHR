<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // الواجهة والـ API على نفس الأصل (proxy): جلسة بكوكي httpOnly + حماية CSRF (هيدر X-XSRF-TOKEN)
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocale::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \App\Http\Middleware\ResolveTenant::class,
        ]);
        $middleware->alias([
            'fresh' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'portal' => \App\Http\Middleware\RequirePortal::class,
            'role' => \App\Http\Middleware\RequireRole::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // كل أخطاء الـ API ترجع JSON
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
