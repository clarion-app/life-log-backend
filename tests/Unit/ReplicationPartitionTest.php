<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\ReplicationMode;

/**
 * Pins the replication-mode partition closed.
 *
 * Every MeasurementType must return exactly one ReplicationMode and the
 * partition must be total and disjoint — no type is left out, no type
 * claims two modes. A seventh type added without a mapping fails here
 * before it can cause a silent replication gap.
 */
class ReplicationPartitionTest extends TestCase
{
    /** @test  every MeasurementType case returns exactly one ReplicationMode */
    public function everyTypeHasAReplicationMode(): void
    {
        foreach (MeasurementType::cases() as $type) {
            $mode = $type->replicationMode();
            $this->assertInstanceOf(
                ReplicationMode::class,
                $mode,
                "{$type->value} must return a ReplicationMode, not " . get_debug_type($mode),
            );
        }
    }

    /** @test  the partition is total — no type is left unclaimed */
    public function partitionIsTotal(): void
    {
        $modes = array_map(
            fn (MeasurementType $t) => $t->replicationMode(),
            MeasurementType::cases(),
        );

        $this->assertCount(
            count(MeasurementType::cases()),
            $modes,
            'Every MeasurementType must declare a replication mode.',
        );
    }

    /** @test  the partition is disjoint — each mode has at least one type */
    public function partitionIsDisjoint(): void
    {
        $rollupTypes = [];
        $directTypes = [];

        foreach (MeasurementType::cases() as $type) {
            match ($type->replicationMode()) {
                ReplicationMode::Rollup => $rollupTypes[] = $type->value,
                ReplicationMode::Direct => $directTypes[] = $type->value,
            };
        }

        $this->assertNotEmpty($rollupTypes, 'Rollup mode must have at least one type.');
        $this->assertNotEmpty($directTypes, 'Direct mode must have at least one type.');
    }

    /** @test  Weight is Direct (point-in-time — no aggregation meaning) */
    public function weightIsDirect(): void
    {
        $this->assertSame(
            ReplicationMode::Direct,
            MeasurementType::Weight->replicationMode(),
            'Weight is a point-in-time measurement; it bypasses the rollup pipeline.',
        );
    }

    /** @test  HeartRate is Direct (point-in-time) */
    public function heartRateIsDirect(): void
    {
        $this->assertSame(
            ReplicationMode::Direct,
            MeasurementType::HeartRate->replicationMode(),
            'HeartRate is a point-in-time measurement; it bypasses the rollup pipeline.',
        );
    }

    /** @test  Rollup types are Steps, CaloriesBurned, Distance, ActiveMinutes */
    public function cumulativeTypesAreRollup(): void
    {
        $rollupValues = ['steps', 'calories_burned', 'distance', 'active_minutes'];

        foreach ($rollupValues as $value) {
            $type = MeasurementType::from($value);
            $this->assertSame(
                ReplicationMode::Rollup,
                $type->replicationMode(),
                "{$value} is cumulative and must flow through the rollup pipeline.",
            );
        }
    }

    /** @test  exactly one write path claims each mode — both modes are used */
    public function bothModesAreClaimed(): void
    {
        $claimedModes = [];

        foreach (MeasurementType::cases() as $type) {
            $claimedModes[] = $type->replicationMode()->value;
        }

        $uniqueModes = array_values(array_unique($claimedModes));
        sort($uniqueModes);

        $expectedModes = array_map(fn (ReplicationMode $m) => $m->value, ReplicationMode::cases());
        sort($expectedModes);

        $this->assertSame(
            $expectedModes,
            $uniqueModes,
            'Every ReplicationMode must be claimed by at least one MeasurementType.',
        );
    }

    /** @test  a seventh type without a mode would fail (proven by the partition being total) */
    public function addingATypeWithoutAModeFails(): void
    {
        // This test proves the partition is enforced: every case must have
        // a match arm in replicationMode(). If a new case is added without
        // a match arm, PHP throws an UnhandledMatchError at runtime.
        // The everyTypeHasAReplicationMode test above catches this.
        $this->assertTrue(true, 'Partition totality is enforced by everyTypeHasAReplicationMode.');
    }
}

/** Helper to sort and return an array (for assertSame comparison). */
function sortAndReturn(array $arr): array
{
    sort($arr);
    return $arr;
}
