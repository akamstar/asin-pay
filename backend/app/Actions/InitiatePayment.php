<?php

namespace App\Actions;

use App\Enums\PaymentOperator;
use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestStatus;
use App\Jobs\InitiatePaymentJob;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Payments\Exceptions\PaymentNotAllowedException;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

class InitiatePayment
{
    public function __construct(private readonly ReferenceGenerator $references) {}

    /**
     * Crée une tentative de paiement et confie l'appel au service de paiement à la file d'attente.
     * La demande est verrouillée : deux clics simultanés ne peuvent pas créer deux paiements.
     *
     * @throws PaymentNotAllowedException
     */
    public function handle(ServiceRequest $serviceRequest, string $phoneNumber, PaymentOperator $operator): Payment
    {
        return DB::transaction(function () use ($serviceRequest, $phoneNumber, $operator): Payment {
            $locked = ServiceRequest::whereKey($serviceRequest->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPayable()) {
                throw new PaymentNotAllowedException(match ($locked->status) {
                    ServiceRequestStatus::Paid => 'Cette demande est déjà payée.',
                    default => 'Un paiement est déjà en cours pour cette demande.',
                });
            }

            $payment = $locked->payments()->create([
                'code' => $this->references->generate(ReferenceGenerator::PAYMENT_PREFIX),
                'phone_number' => $phoneNumber,
                'operator' => $operator,
                'amount' => $locked->amount,
                'status' => PaymentStatus::Pending,
                'status_at' => now(),
            ]);

            $locked->update(['status' => ServiceRequestStatus::PaymentInProgress, 'status_at' => now()]);

            InitiatePaymentJob::dispatch($payment)->afterCommit();

            return $payment;
        });
    }
}
