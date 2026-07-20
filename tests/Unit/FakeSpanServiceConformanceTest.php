<?php

namespace Tests\Unit;

use Tests\Support\FakeSpanService;
use Tests\Support\HealthServiceConformanceTestCase;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;

/**
 * Nested envelopes, metric units, opaque continuation tokens, sessions as well
 * as measurements — a service built to disagree with FakeStepService on every
 * dimension the contract is supposed to absorb.
 *
 * Two services this dissimilar passing the identical suite, with neither file
 * saying anything about the other, is the evidence the boundary holds (SC-003).
 */
class FakeSpanServiceConformanceTest extends HealthServiceConformanceTestCase
{
    protected function service(): ExternalHealthService
    {
        return new FakeSpanService();
    }
}
