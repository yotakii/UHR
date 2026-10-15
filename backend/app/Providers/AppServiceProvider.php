<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // تطوير صارم: كشف lazy loading والحقول غير المعرّفة مبكراً
        Model::shouldBeStrict(! $this->app->isProduction());

        // حدود المحاولات: 10 دخول بالدقيقة لكل عنوان+بريد، و3 طلبات استعادة كلمة مرور بالدقيقة
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by(strtolower((string) $r->input('email')).'|'.$r->ip())
            ->response(fn () => response()->json(['message' => \App\Support\L::t('محاولات كثيرة خلال وقت قصير. انتظر دقيقة ثم حاول مجدداً.', 'Too many attempts. Wait a minute and try again.'), 'code' => 'throttled'], 429)));
        RateLimiter::for('password-email', fn (Request $r) => Limit::perMinute(3)->by($r->ip())
            ->response(fn () => response()->json(['message' => \App\Support\L::t('طلبات كثيرة. انتظر دقيقة ثم حاول مجدداً.', 'Too many requests. Wait a minute and try again.'), 'code' => 'throttled'], 429)));
    }
}
