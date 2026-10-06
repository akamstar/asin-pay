<?php

namespace App\Providers;

use App\Payments\PaymentGatewayClient;
use App\Payments\SignatureVerifier;
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
        $this->app->singleton(SignatureVerifier::class, fn (): SignatureVerifier => new SignatureVerifier(
            publicKey: (string) config('services.payment.public_key'),
            keyId: (string) config('services.payment.key_id'),
            toleranceSeconds: (int) config('services.payment.signature_tolerance'),
        ));

        $this->app->singleton(PaymentGatewayClient::class, fn ($app): PaymentGatewayClient => new PaymentGatewayClient(
            baseUrl: (string) config('services.payment.base_url'),
            apiKey: (string) config('services.payment.api_key'),
            timeoutSeconds: (int) config('services.payment.timeout'),
            verifier: $app->make(SignatureVerifier::class),
        ));
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

        RateLimiter::for('payments.create', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(300)->by($request->ip()));
    }
}
