<?php

namespace App\Payments;

use App\Models\Payment;
use App\Payments\Exceptions\InvalidSignatureException;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\Exceptions\PaymentRejectedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use UnexpectedValueException;

/**
 * Client du service de paiement. Chaque réponse est authentifiée par sa signature
 * avant d'être exploitée.
 */
class PaymentGatewayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
        private readonly SignatureVerifier $verifier,
    ) {}

    /**
     * Demande le débit. Le code du paiement sert de clé d'idempotence : un nouvel
     * essai après une erreur réseau ne crée jamais un second débit.
     *
     * @throws PaymentGatewayException|PaymentRejectedException|InvalidSignatureException
     */
    public function initiate(Payment $payment): GatewayPayment
    {
        return $this->send(fn (PendingRequest $request): Response => $request
            ->withHeader('Idempotency-Key', $payment->code)
            ->post('/api/v1/payments', [
                'merchant_reference' => $payment->code,
                'amount' => $payment->amount,
                'currency' => 'XOF',
                'phone' => $payment->phone_number,
                'operator' => $payment->operator->value,
            ]));
    }

    /**
     * Consulte l'état d'un paiement (réconciliation).
     *
     * @throws PaymentGatewayException|PaymentRejectedException|InvalidSignatureException
     */
    public function find(string $providerPaymentId): GatewayPayment
    {
        return $this->send(fn (PendingRequest $request): Response => $request
            ->get('/api/v1/payments/'.rawurlencode($providerPaymentId)));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): GatewayPayment
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->withHeader('X-Api-Key', $this->apiKey);

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Service de paiement injoignable : '.$e->getMessage(), previous: $e);
        }

        if ($response->serverError()) {
            throw new PaymentGatewayException("Service de paiement en erreur (HTTP {$response->status()}).");
        }
        if (! $response->successful()) {
            throw new PaymentRejectedException("Requête refusée par le service de paiement (HTTP {$response->status()}) : {$response->body()}");
        }

        $this->verifier->verify(
            $response->body(),
            $response->header(SignatureVerifier::HEADER_SIGNATURE),
            $response->header(SignatureVerifier::HEADER_TIMESTAMP),
            $response->header(SignatureVerifier::HEADER_KEY_ID),
        );

        try {
            $data = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

            return GatewayPayment::fromArray(is_array($data) ? $data : []);
        } catch (JsonException|UnexpectedValueException $e) {
            throw new PaymentGatewayException('Réponse illisible du service de paiement.', previous: $e);
        }
    }
}
