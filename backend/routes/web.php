<?php

use App\Http\Controllers\ServiceRequestPageController;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\Route;

Route::get('/', [ServiceRequestPageController::class, 'create'])->name('service-requests.create');

Route::get('demandes/{serviceRequest}', [ServiceRequestPageController::class, 'show'])
    ->where('serviceRequest', ReferenceGenerator::regex())
    ->middleware('throttle:api')
    ->name('service-requests.show');
