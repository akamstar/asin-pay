<?php

namespace Database\Factories;

use App\Enums\PaymentOperator;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Support\ReferenceGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (new ReferenceGenerator)->generate(ReferenceGenerator::PAYMENT_PREFIX),
            'service_request_id' => ServiceRequest::factory(),
            'phone_number' => '01'.fake()->numerify('########'),
            'operator' => fake()->randomElement(PaymentOperator::cases()),
            'amount' => fn (array $attributes): int => ServiceRequest::find($attributes['service_request_id'])->amount,
            'provider_payment_id' => null,
            'status' => PaymentStatus::Pending,
            'failure_reason' => null,
            'status_at' => now(),
        ];
    }

    public function withProviderId(): static
    {
        return $this->state(fn (): array => ['provider_payment_id' => 'pay_'.fake()->unique()->regexify('[a-f0-9]{32}')]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Success]);
    }

    public function failed(string $reason = 'TRANSACTION_REFUSEE'): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Failed, 'failure_reason' => $reason]);
    }
}
