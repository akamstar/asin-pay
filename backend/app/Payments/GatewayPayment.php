<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use UnexpectedValueException;

/** État d'un paiement tel que le décrit le service de paiement (réponse ou webhook). */
final readonly class GatewayPayment
{
    public function __construct(
        public string $id,
        public string $merchantReference,
        public int $amount,
        public PaymentStatus $status,
        public ?string $failureReason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = PaymentStatus::tryFrom((string) ($data['status'] ?? ''));

        if (! is_string($data['id'] ?? null) || ! is_string($data['merchant_reference'] ?? null)
            || ! is_int($data['amount'] ?? null) || $status === null) {
            throw new UnexpectedValueException('Paiement mal formé reçu du service de paiement.');
        }

        return new self(
            id: $data['id'],
            merchantReference: $data['merchant_reference'],
            amount: $data['amount'],
            status: $status,
            failureReason: isset($data['failure_reason']) ? (string) $data['failure_reason'] : null,
        );
    }
}
