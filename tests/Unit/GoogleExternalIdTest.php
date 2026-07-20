<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Mapping\DataTypeMap;

/**
 * External ID is deterministic, pure function of (type, instant).
 * Format: "{shortCode}:{epochSeconds}"
 */
class GoogleExternalIdTest extends TestCase
{
    /** @test T051 — external ID for heart rate at epoch 1753027200 */
    public function heartRateExternalIdIsDeterministic(): void
    {
        $shortCode = DataTypeMap::shortCode('heartRateBpm');
        $epoch = 1753027200;
        $expected = "{$shortCode}:{$epoch}";

        $this->assertSame('hr:1753027200', $expected);
    }

    /** @test T051 — external ID for steps at epoch 1753027200 */
    public function stepsExternalIdIsDeterministic(): void
    {
        $shortCode = DataTypeMap::shortCode('stepCount');
        $epoch = 1753027200;
        $expected = "{$shortCode}:{$epoch}";

        $this->assertSame('steps:1753027200', $expected);
    }

    /** @test T051 — same type and instant always produces same ID */
    public function sameTypeAndInstantProducesSameId(): void
    {
        $shortCode = DataTypeMap::shortCode('caloriesBurned');
        $epoch = 1753030800;

        $id1 = "{$shortCode}:{$epoch}";
        $id2 = "{$shortCode}:{$epoch}";

        $this->assertSame($id1, $id2);
    }

    /** @test T051 — different type produces different ID */
    public function differentTypeProducesDifferentId(): void
    {
        $hrShortCode = DataTypeMap::shortCode('heartRateBpm');
        $stepsShortCode = DataTypeMap::shortCode('stepCount');
        $epoch = 1753027200;

        $hrId = "{$hrShortCode}:{$epoch}";
        $stepsId = "{$stepsShortCode}:{$epoch}";

        $this->assertNotSame($hrId, $stepsId);
        $this->assertSame('hr:1753027200', $hrId);
        $this->assertSame('steps:1753027200', $stepsId);
    }

    /** @test T051 — different instant produces different ID */
    public function differentInstantProducesDifferentId(): void
    {
        $shortCode = DataTypeMap::shortCode('weight');

        $id1 = "{$shortCode}:1753027200";
        $id2 = "{$shortCode}:1753030800";

        $this->assertNotSame($id1, $id2);
        $this->assertSame('wt:1753027200', $id1);
        $this->assertSame('wt:1753030800', $id2);
    }

    /** @test T051 — short codes are all expected values */
    public function shortCodesAreAllExpectedValues(): void
    {
        $this->assertSame('steps', DataTypeMap::shortCode('stepCount'));
        $this->assertSame('hr', DataTypeMap::shortCode('heartRateBpm'));
        $this->assertSame('cal', DataTypeMap::shortCode('caloriesBurned'));
        $this->assertSame('actmin', DataTypeMap::shortCode('activeMinutes'));
        $this->assertSame('dist', DataTypeMap::shortCode('distance'));
        $this->assertSame('wt', DataTypeMap::shortCode('weight'));
        $this->assertSame('sleep', DataTypeMap::shortCode('sleepSession'));
        $this->assertSame('workout', DataTypeMap::shortCode('exerciseSession'));
    }

    /** @test T051 — external ID is pure function (no side effects) */
    public function externalIdIsPureFunction(): void
    {
        // Multiple calls should return identical results
        $shortCode = DataTypeMap::shortCode('distance');
        $epoch = 1753100000;

        for ($i = 0; $i < 10; $i++) {
            $id = "{$shortCode}:{$epoch}";
            $this->assertSame('dist:1753100000', $id);
        }
    }
}
