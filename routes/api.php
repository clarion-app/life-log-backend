<?php

use Illuminate\Support\Facades\Route;
use ClarionApp\LifeLogBackend\Controllers\EntryController;
use ClarionApp\LifeLogBackend\Controllers\LocationController;
use ClarionApp\LifeLogBackend\Controllers\HealthMetricController;
use ClarionApp\LifeLogBackend\Controllers\ConnectedAccountController;
use Illuminate\Session\Middleware\StartSession;

Route::group(['prefix'=>$this->routePrefix, 'middleware' => [StartSession::class, 'auth:api']], function () {
    Route::resource('entry', EntryController::class);
    Route::resource('location', LocationController::class);
    Route::resource('health-metric', HealthMetricController::class);

    // Connected account sync endpoints
    Route::post('life-log/connected-accounts/{id}/sync', [ConnectedAccountController::class, 'sync']);
    Route::get('life-log/connected-accounts/{id}', [ConnectedAccountController::class, 'show']);
});