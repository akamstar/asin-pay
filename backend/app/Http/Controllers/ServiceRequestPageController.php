<?php

namespace App\Http\Controllers;

use App\Enums\PaymentOperator;
use App\Http\Resources\ServiceRequestResource;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Contracts\View\View;

class ServiceRequestPageController extends Controller
{
    /**
     * Formulaire de demande. Le catalogue est injecté dans la page pour éviter un aller-retour.
     */
    public function create(): View
    {
        return view('service-requests.create', [
            'services' => ServiceResource::collection(Service::active()->orderBy('title')->get())->resolve(),
            'fees' => (int) config('service_requests.fees'),
            'maxQuantity' => (int) config('service_requests.max_quantity'),
        ]);
    }

    /**
     * Récapitulatif d'une demande, montant à payer et paiement.
     */
    public function show(ServiceRequest $serviceRequest): View
    {
        $serviceRequest->load(['service', 'latestPayment']);

        return view('service-requests.show', [
            'serviceRequest' => $serviceRequest,
            // État initial du composant de paiement : même format que l'API interrogée ensuite.
            'state' => ServiceRequestResource::make($serviceRequest)->resolve(),
            'operators' => PaymentOperator::options(),
        ]);
    }
}
