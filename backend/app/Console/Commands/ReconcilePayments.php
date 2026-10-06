<?php

namespace App\Console\Commands;

use App\Actions\ApplyPaymentResult;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\PaymentGatewayClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('payments:reconcile')]
#[Description('Interroge le service de paiement pour les paiements restés en cours trop longtemps (webhook perdu).')]
class ReconcilePayments extends Command
{
    public function handle(PaymentGatewayClient $gateway, ApplyPaymentResult $applyResult): int
    {
        $threshold = now()->subSeconds((int) config('services.payment.reconcile_after'));
        $resolved = 0;

        Payment::pending()
            ->where('status_at', '<=', $threshold)
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (Payment $payment) use ($gateway, $applyResult, &$resolved): void {
                // Jamais transmis au service (file d'attente bloquée, échecs répétés) : la demande redevient payable.
                if ($payment->provider_payment_id === null) {
                    $applyResult->handle($payment, PaymentStatus::Failed, PaymentFailureReason::ProviderUnavailable->value);
                    $resolved++;

                    return;
                }

                try {
                    $result = $gateway->find($payment->provider_payment_id);
                } catch (Throwable $e) {
                    Log::warning('Réconciliation impossible pour ce paiement.', ['payment' => $payment->code, 'error' => $e->getMessage()]);

                    return;
                }

                if ($result->status->isFinal()) {
                    $applyResult->handle($payment, $result->status, $result->failureReason, $result->id);
                    $resolved++;
                }
            });

        $this->info("Paiements réconciliés : {$resolved}.");

        return self::SUCCESS;
    }
}
