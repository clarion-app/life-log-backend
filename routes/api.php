<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix'=>'api/clarion-app/life-log', 'middleware' => 'auth:api'], function () {
});