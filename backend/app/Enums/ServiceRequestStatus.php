<?php

namespace App\Enums;

enum ServiceRequestStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case PaymentInProgress = 'PAYMENT_IN_PROGRESS';
    case Paid = 'PAID';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'En attente de paiement',
            self::PaymentInProgress => 'Paiement en cours',
            self::Paid => 'Payée',
        };
    }
}
