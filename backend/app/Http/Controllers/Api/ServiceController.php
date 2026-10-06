<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    /**
     * Services actifs, avec les paramètres utiles au calcul du total côté front.
     */
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(Service::active()->orderBy('title')->get())
            ->additional(['meta' => [
                'fees' => (int) config('service_requests.fees'),
                'max_quantity' => (int) config('service_requests.max_quantity'),
                'currency' => 'XOF',
            ]]);
    }
}
