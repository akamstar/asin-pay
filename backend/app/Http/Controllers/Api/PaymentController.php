<?php

namespace App\Http\Controllers\Api;

use App\Actions\InitiatePayment;
use App\Enums\PaymentOperator;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    /**
     * Lance le paiement d'une demande. La réponse (202) indique que le paiement est en
     * cours : le résultat arrive plus tard, le client suit le statut de la demande.
     */
    public function store(StorePaymentRequest $request, ServiceRequest $serviceRequest, InitiatePayment $initiatePayment): JsonResponse
    {
        $initiatePayment->handle(
            $serviceRequest,
            $request->validated('phone_number'),
            $request->enum('operator', PaymentOperator::class),
        );

        return ServiceRequestResource::make($serviceRequest->refresh()->load(['service', 'latestPayment']))
            ->response()
            ->setStatusCode(202);
    }
}
