<?php

namespace App\Providers;

use App\Services\CalorieEstimationService;
use App\Services\Contracts\CalorieEstimationServiceInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            CalorieEstimationServiceInterface::class,
            CalorieEstimationService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                Str::lower($request->string('email')->toString()).'|'.$request->ip(),
            );
        });
    }
}
