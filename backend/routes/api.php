<?php

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
});
