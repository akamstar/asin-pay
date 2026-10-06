<?php

namespace App\Http\Controllers;

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
     * Récapitulatif d'une demande et montant à payer.
     */
    public function show(ServiceRequest $serviceRequest): View
    {
        return view('service-requests.show', [
            'serviceRequest' => $serviceRequest->load('service'),
        ]);
    }
}
