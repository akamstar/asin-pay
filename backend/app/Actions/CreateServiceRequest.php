<?php

namespace App\Actions;

use App\Enums\ServiceRequestStatus;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Services\PricingCalculator;
use App\Support\ReferenceGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateServiceRequest
{
    private const MAX_REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly PricingCalculator $calculator,
        private readonly ReferenceGenerator $references,
    ) {}

    /**
     * Enregistre une demande. Le prix du service et les frais sont figés au
     * moment de la création : une évolution ultérieure du tarif ne modifie pas
     * le montant d'une demande existante.
     */
    public function handle(Service $service, int $quantity): ServiceRequest
    {
        $pricing = $this->calculator->calculate($service->price, $quantity, (int) config('service_requests.fees'));

        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                // Savepoint : sous PostgreSQL, une collision n'invalide pas la transaction englobante.
                return DB::transaction(fn () => ServiceRequest::create([
                    'code' => $this->references->generate(),
                    'service_id' => $service->id,
                    'unit_price' => $pricing->unitPrice,
                    'quantity' => $pricing->quantity,
                    'fees' => $pricing->fees,
                    'amount' => $pricing->total,
                    'status' => ServiceRequestStatus::PendingPayment,
                    'status_at' => now(),
                ])->setRelation('service', $service));
            } catch (UniqueConstraintViolationException $e) {
                // Collision de référence (très improbable) : on réessaie avec une nouvelle.
                $last = $e;
            }
        }

        throw new RuntimeException('Impossible de générer une référence unique.', previous: $last);
    }
}
