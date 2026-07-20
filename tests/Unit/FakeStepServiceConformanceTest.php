<?php

namespace Tests\Unit;

use Tests\Support\FakeStepService;
use Tests\Support\HealthServiceConformanceTestCase;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;

/**
 * Flat scalar rows, imperial units, integer-offset paging, measurements only.
 *
 * The file is this short on purpose. Anything added here beyond naming the
 * service would be an accommodation the other service does not get, and the
 * suite would stop being a shared standard.
 */
class FakeStepServiceConformanceTest extends HealthServiceConformanceTestCase
{
    protected function service(): ExternalHealthService
    {
        return new FakeStepService();
    }
}
