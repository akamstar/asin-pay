<?php

namespace App\Jobs;

use App\Actions\ApplyPaymentResult;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\Exceptions\InvalidSignatureException;
use App\Payments\Exceptions\PaymentRejectedException;
use App\Payments\PaymentGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Transmet la demande de débit au service de paiement.
 *
 * Les erreurs transitoires (réseau, 5xx) sont retentées : l'Idempotency-Key garantit
 * qu'un nouvel essai ne débite pas deux fois. Une requête refusée ou une réponse dont
 * la signature est invalide met fin aux essais.
 */
class InitiatePaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [2, 5, 10, 30];

    public function __construct(public Payment $payment) {}

    public function handle(PaymentGatewayClient $gateway, ApplyPaymentResult $applyResult): void
    {
        $payment = $this->payment->fresh();
        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        try {
            $result = $gateway->initiate($payment);

            if ($result->merchantReference !== $payment->code || $result->amount !== $payment->amount) {
                throw new UnexpectedValueException('Réponse du service de paiement incohérente avec le paiement envoyé.');
            }
        } catch (PaymentRejectedException|InvalidSignatureException|UnexpectedValueException $e) {
            $this->fail($e);

            return;
        }

        if ($result->status->isFinal()) {
            $applyResult->handle($payment, $result->status, $result->failureReason, $result->id);

            return;
        }

        // Mise à jour ciblée : ne pas écraser un résultat déjà reçu par webhook entre-temps.
        Payment::whereKey($payment->getKey())->whereNull('provider_payment_id')->update(['provider_payment_id' => $result->id]);
    }

    /**
     * Échec définitif : la demande redevient payable. Un succès tardif reste accepté
     * (voir ApplyPaymentResult).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Transmission du paiement impossible.', [
            'payment' => $this->payment->code, 'error' => $exception?->getMessage(),
        ]);

        app(ApplyPaymentResult::class)->handle($this->payment, PaymentStatus::Failed, PaymentFailureReason::ProviderUnavailable->value);
    }
}
