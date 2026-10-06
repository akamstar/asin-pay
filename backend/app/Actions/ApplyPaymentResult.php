<?php

namespace App\Actions;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Payment;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Applique le résultat d'un paiement (webhook, réponse du service ou réconciliation).
 *
 * Idempotent : un résultat déjà appliqué est ignoré. Les verrous sont toujours
 * pris dans le même ordre (demande puis paiement) pour éviter les interblocages.
 */
class ApplyPaymentResult
{
    public function handle(Payment $payment, PaymentStatus $status, ?string $failureReason = null, ?string $providerPaymentId = null): Payment
    {
        if (! $status->isFinal()) {
            throw new InvalidArgumentException('Seul un résultat final (SUCCESS ou FAILED) peut être appliqué.');
        }

        return DB::transaction(function () use ($payment, $status, $failureReason, $providerPaymentId): Payment {
            $serviceRequest = ServiceRequest::whereKey($payment->service_request_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($providerPaymentId !== null && $payment->provider_payment_id === null) {
                $payment->provider_payment_id = $providerPaymentId;
            }

            if (! $this->canTransition($payment, $status)) {
                $payment->save();
                Log::info('Résultat de paiement ignoré (déjà appliqué ou incompatible).', [
                    'payment' => $payment->code, 'current' => $payment->status->value, 'received' => $status->value,
                ]);

                return $payment;
            }

            $payment->fill([
                'status' => $status,
                'failure_reason' => $status === PaymentStatus::Failed ? ($failureReason ?? 'UNKNOWN') : null,
                'status_at' => now(),
            ])->save();

            $status === PaymentStatus::Success
                ? $this->markPaid($serviceRequest, $payment)
                : $this->reopenIfNothingPending($serviceRequest);

            Log::info('Résultat de paiement appliqué.', [
                'payment' => $payment->code, 'service_request' => $serviceRequest->code, 'status' => $status->value,
            ]);

            return $payment;
        });
    }

    /**
     * Un paiement en cours accepte tout résultat final. Un paiement marqué en échec faute
     * d'avoir pu joindre le service accepte un succès tardif : le débit a bien eu lieu.
     */
    private function canTransition(Payment $payment, PaymentStatus $status): bool
    {
        return $payment->status === PaymentStatus::Pending
            || ($payment->status === PaymentStatus::Failed
                && $payment->failure_reason === PaymentFailureReason::ProviderUnavailable->value
                && $status === PaymentStatus::Success);
    }

    private function markPaid(ServiceRequest $serviceRequest, Payment $payment): void
    {
        if ($serviceRequest->status === ServiceRequestStatus::Paid) {
            Log::critical('Double paiement détecté : remboursement à prévoir.', [
                'service_request' => $serviceRequest->code, 'payment' => $payment->code,
            ]);

            return;
        }

        $serviceRequest->update(['status' => ServiceRequestStatus::Paid, 'status_at' => now()]);
    }

    private function reopenIfNothingPending(ServiceRequest $serviceRequest): void
    {
        if ($serviceRequest->status === ServiceRequestStatus::PaymentInProgress
            && ! $serviceRequest->payments()->pending()->exists()) {
            $serviceRequest->update(['status' => ServiceRequestStatus::PendingPayment, 'status_at' => now()]);
        }
    }
}
