<?php

namespace App\Providers;

use App\Services\StripeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeService::class, fn () => new StripeService(
            config('services.stripe.key'),
            config('services.stripe.webhook_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth.login', fn (Request $request): array => [
            Limit::perMinute(5)->by($this->authThrottleKey($request, $request->input('email'))),
            Limit::perMinute(20)->by($this->ipThrottleKey($request)),
        ]);

        RateLimiter::for('auth.signup', fn (Request $request): array => [
            Limit::perMinute(3)->by($this->authThrottleKey($request, $request->input('email'))),
            Limit::perMinute(10)->by($this->ipThrottleKey($request)),
        ]);

        RateLimiter::for('auth.password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by($this->authThrottleKey(
                $request,
                $request->input('email', $request->input('token')),
            )),
            Limit::perMinute(10)->by($this->ipThrottleKey($request)),
        ]);
    }

    private function authThrottleKey(Request $request, mixed $identity): string
    {
        $normalized = is_scalar($identity) ? Str::lower(trim((string) $identity)) : '';

        if ($normalized === '') {
            $normalized = 'anonymous';
        }

        return 'auth:'.hash('sha256', $normalized).'|'.$this->ipThrottleKey($request);
    }

    private function ipThrottleKey(Request $request): string
    {
        return 'ip:'.($request->ip() ?: 'unknown');
    }
}
