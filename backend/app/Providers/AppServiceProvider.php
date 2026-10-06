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

    /**
     * Sans compte usager, la référence d'une demande sert de clé d'accès :
     * on limite les appels par IP pour rendre l'énumération impraticable.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('service-requests.create', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }
}
