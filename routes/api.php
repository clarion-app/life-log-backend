<?php

use Illuminate\Support\Facades\Route;
use ClarionApp\LifeLogBackend\Controllers\EntryController;
use ClarionApp\LifeLogBackend\Controllers\LocationController;
use ClarionApp\LifeLogBackend\Controllers\HealthMetricController;
use ClarionApp\LifeLogBackend\Controllers\ConnectedAccountController;
use ClarionApp\LifeLogBackend\Controllers\ServiceCredentialController;
use Illuminate\Session\Middleware\StartSession;

Route::group(['prefix'=>$this->routePrefix, 'middleware' => [StartSession::class, 'auth:api']], function () {
    Route::resource('entry', EntryController::class);
    Route::resource('location', LocationController::class);
    Route::resource('health-metric', HealthMetricController::class);

    // Service credential endpoints (any authenticated user — FR-025, FR-026)
    Route::get('service-credentials', [ServiceCredentialController::class, 'index']);
    Route::post('service-credentials', [ServiceCredentialController::class, 'store']);
    Route::put('service-credentials/{service}', [ServiceCredentialController::class, 'update']);
    Route::post('service-credentials/{service}/verify', [ServiceCredentialController::class, 'verify']);
    Route::delete('service-credentials/{service}', [ServiceCredentialController::class, 'destroy']);

    // Connected account endpoints (callback must be declared before {id})
    Route::get('connected-accounts', [ConnectedAccountController::class, 'index']);
    Route::post('connected-accounts', [ConnectedAccountController::class, 'store']);
    Route::post('connected-accounts/callback', [ConnectedAccountController::class, 'callback']);
    Route::post('connected-accounts/{id}/sync', [ConnectedAccountController::class, 'sync']);
    Route::get('connected-accounts/{id}', [ConnectedAccountController::class, 'show']);
    Route::delete('connected-accounts/{id}', [ConnectedAccountController::class, 'destroy']);
});