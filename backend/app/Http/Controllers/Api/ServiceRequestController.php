<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateServiceRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;

class ServiceRequestController extends Controller
{
    /**
     * Enregistre une demande et renvoie le montant à payer.
     */
    public function store(StoreServiceRequestRequest $request, CreateServiceRequest $createServiceRequest): JsonResponse
    {
        $service = Service::active()->where('code', $request->validated('service_code'))->firstOrFail();

        $serviceRequest = $createServiceRequest->handle($service, (int) $request->validated('quantity'));

        return ServiceRequestResource::make($serviceRequest)
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.service-requests.show', $serviceRequest));
    }

    /**
     * Consulte une demande par sa référence publique.
     */
    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return ServiceRequestResource::make($serviceRequest->load('service'));
    }
}
