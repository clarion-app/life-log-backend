<?php

namespace ClarionApp\LifeLogBackend;

use Illuminate\Support\ServiceProvider;

class LifeLogBackendServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}