<?php

namespace App\Http\Resources;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'amount' => $this->amount,
            'phone_number' => $this->maskedPhoneNumber(),
            'operator' => $this->operator->value,
            'operator_label' => $this->operator->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'failure_reason' => $this->failure_reason,
            'failure_message' => $this->when(
                $this->status === PaymentStatus::Failed,
                fn (): string => PaymentFailureReason::messageFor($this->failure_reason),
            ),
            'status_at' => $this->status_at->toIso8601String(),
        ];
    }
}
