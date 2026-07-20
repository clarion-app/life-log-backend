<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Tests\Support\FakeStepService;
use Tests\Support\FakeSpanService;
use Tests\Support\StubBandService;

/**
 * T011 — maxWindow(MeasurementType|SessionType): ?DateInterval is declared on
 * ExternalHealthService and returns without throwing for every supportedTypes()
 * entry of each in-package fake.
 */
class MaxWindowContractTest extends TestCase
{
    /** @return list<ExternalHealthService> */
    private function inPackageFakes(): array
    {
        return [
            new FakeStepService(),
            new FakeSpanService(),
            new StubBandService(),
        ];
    }

    /** @test  every in-package fake declares maxWindow for every supported type */
    public function maxWindowReturnsForEverySupportedType(): void
    {
        foreach ($this->inPackageFakes() as $service) {
            foreach ($service->supportedTypes() as $type) {
                $result = $service->maxWindow($type);

                $this->assertTrue(
                    $result === null || $result instanceof \DateInterval,
                    "{$service->name()}::maxWindow({$type->value}) must return ?DateInterval, "
                    . "got " . get_debug_type($result),
                );
            }
        }
    }

    /** @test  maxWindow does not throw for any supported type */
    public function maxWindowDoesNotThrow(): void
    {
        foreach ($this->inPackageFakes() as $service) {
            foreach ($service->supportedTypes() as $type) {
                // If this throws, the test fails.
                $service->maxWindow($type);
            }
        }

        $this->assertTrue(true, 'maxWindow returned without throwing for all fakes.');
    }

    /** @test  maxWindow accepts both MeasurementType and SessionType */
    public function maxWindowAcceptsBothEnumKinds(): void
    {
        $span = new FakeSpanService();

        // FakeSpanService supports both MeasurementType and SessionType
        foreach ($span->supportedTypes() as $type) {
            $result = $span->maxWindow($type);
            $this->assertTrue(
                $result === null || $result instanceof \DateInterval,
                "maxWindow({$type->value}) returned " . get_debug_type($result),
            );
        }
    }
}
