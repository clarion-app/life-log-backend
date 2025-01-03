<?php

namespace ClarionApp\LifeLogBackend;

use ClarionApp\Backend\ClarionPackageServiceProvider;

class LifeLogBackendServiceProvider extends ClarionPackageServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}