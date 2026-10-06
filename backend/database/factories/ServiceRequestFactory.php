<?php

namespace Database\Factories;

use App\Enums\ServiceRequestStatus;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Support\ReferenceGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(1, 30) * 100;
        $quantity = fake()->numberBetween(1, 5);
        $fees = 100;

        return [
            'code' => (new ReferenceGenerator)->generate(),
            'service_id' => Service::factory()->state(['price' => $unitPrice]),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'fees' => $fees,
            'amount' => $unitPrice * $quantity + $fees,
            'status' => ServiceRequestStatus::PendingPayment,
            'status_at' => now(),
        ];
    }

    public function paymentInProgress(): static
    {
        return $this->state(fn (): array => ['status' => ServiceRequestStatus::PaymentInProgress]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => ['status' => ServiceRequestStatus::Paid]);
    }
}
