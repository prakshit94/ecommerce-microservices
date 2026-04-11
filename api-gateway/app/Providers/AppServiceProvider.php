<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Default API limit: 60/min per user or IP
        RateLimiter::for('api', function (Request $request) {
            $identity = $request->header('X-User-Id') ?: $request->ip();
            return Limit::perMinute(60)->by($identity);
        });

        // Authenticated users get higher limit: 1000/min
        RateLimiter::for('api.authenticated', function (Request $request) {
            $userId = $request->header('X-User-Id');
            return $userId
                ? Limit::perMinute(1000)->by("user:{$userId}")
                : Limit::perMinute(60)->by("ip:{$request->ip()}");
        });
    }
}
