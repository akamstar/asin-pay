<?php

namespace Tests\Concerns;

use App\Models\Payment;
use App\Payments\PaymentGatewayClient;
use App\Payments\SignatureVerifier;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Simule le service de paiement : paire de clés Ed25519 de test, messages signés
 * comme le fait le service Go (timestamp + "." + corps), réponses HTTP factices.
 */
trait FakesPaymentGateway
{
    protected string $gatewaySecretKey;

    protected function setUpPaymentGateway(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->gatewaySecretKey = sodium_crypto_sign_secretkey($keyPair);

        config([
            'services.payment.base_url' => 'http://payement.test',
            'services.payment.api_key' => 'test-api-key',
            'services.payment.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
            'services.payment.key_id' => 'test-key',
            'services.payment.signature_tolerance' => 300,
        ]);

        // Les singletons doivent être reconstruits avec la configuration de test.
        $this->app->forgetInstance(SignatureVerifier::class);
        $this->app->forgetInstance(PaymentGatewayClient::class);
    }

    /**
     * @return array<string, string>
     */
    protected function signatureHeaders(string $body, ?int $timestamp = null, string $keyId = 'test-key'): array
    {
        $timestamp = (string) ($timestamp ?? now()->timestamp);

        return [
            SignatureVerifier::HEADER_SIGNATURE => base64_encode(sodium_crypto_sign_detached($timestamp.'.'.$body, $this->gatewaySecretKey)),
            SignatureVerifier::HEADER_TIMESTAMP => $timestamp,
            SignatureVerifier::HEADER_KEY_ID => $keyId,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function gatewayPayment(Payment $payment, string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => $payment->provider_payment_id ?? 'pay_'.md5($payment->code),
            'merchant_reference' => $payment->code,
            'amount' => $payment->amount,
            'currency' => 'XOF',
            'phone' => $payment->phone_number,
            'operator' => $payment->operator->value,
            'status' => $status,
            'created_at' => now()->toIso8601String(),
        ], $status === 'FAILED' ? ['failure_reason' => 'TRANSACTION_REFUSEE'] : [], $overrides);
    }

    /**
     * Réponse HTTP signée du service de paiement.
     *
     * @param  array<string, mixed>  $data
     */
    protected function signedGatewayResponse(array $data, int $status = 202): PromiseInterface
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return Http::response($body, $status, ['Content-Type' => 'application/json'] + $this->signatureHeaders($body));
    }

    /**
     * Envoie un webhook avec le corps brut exact qui a été signé.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, string>|null  $headers
     */
    protected function postWebhook(array $event, ?array $headers = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $headers ??= $this->signatureHeaders($body);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/v1/webhooks/paiement', server: $server, content: $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function webhookEvent(Payment $payment, string $status, array $overrides = []): array
    {
        return [
            'id' => 'evt_'.md5(uniqid()),
            'event' => $status === 'SUCCESS' ? 'payment.succeeded' : 'payment.failed',
            'created_at' => now()->toIso8601String(),
            'data' => $this->gatewayPayment($payment, $status, $overrides),
        ];
    }
}
