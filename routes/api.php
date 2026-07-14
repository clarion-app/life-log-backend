<?php

use Illuminate\Support\Facades\Route;
use ClarionApp\LifeLogBackend\Controllers\EntryController;
use ClarionApp\LifeLogBackend\Controllers\LocationController;
use ClarionApp\LifeLogBackend\Controllers\HealthMetricController;

Route::group(['prefix'=>$this->routePrefix, 'middleware' => 'auth:api'], function () {
    Route::resource('entry', EntryController::class);
    Route::resource('location', LocationController::class);
    Route::resource('health-metric', HealthMetricController::class);
});