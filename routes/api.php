<?php

use Illuminate\Support\Facades\Route;
use ClarionApp\LifeLogBackend\Controllers\EntryController;
use ClarionApp\LifeLogBackend\Controllers\LocationController;
use ClarionApp\LifeLogBackend\Controllers\HealthMetricController;

Route::group(['prefix'=>'api/clarion-app/life-log', 'middleware' => 'auth:api'], function () {
    Route::resource('entry', Controllers\EntryController::class);
    Route::resource('location', Controllers\LocationController::class);
    Route::resource('health-metric', Controllers\HealthMetricController::class);
});