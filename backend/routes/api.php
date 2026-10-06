<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->middleware('throttle:api')->group(function () {
    Route::get('services', [ServiceController::class, 'index'])->name('services.index');

    Route::post('service-requests', [ServiceRequestController::class, 'store'])
        ->middleware('throttle:service-requests.create')
        ->name('service-requests.store');

    Route::get('service-requests/{serviceRequest}', [ServiceRequestController::class, 'show'])
        ->where('serviceRequest', ReferenceGenerator::regex())
        ->name('service-requests.show');

    Route::post('service-requests/{serviceRequest}/payments', [PaymentController::class, 'store'])
        ->where('serviceRequest', ReferenceGenerator::regex())
        ->middleware('throttle:payments.create')
        ->name('service-requests.payments.store');
});

// Appelé par le service de paiement : authentifié par signature Ed25519, hors limite « api ».
Route::post('v1/webhooks/paiement', PaymentWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('api.webhooks.payment');
