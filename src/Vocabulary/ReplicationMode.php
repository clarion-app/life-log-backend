<?php

namespace ClarionApp\LifeLogBackend\Vocabulary;

/**
 * How a measurement type reaches the permanent side.
 *
 * Rollup types flow through the hourly aggregation pipeline: raw readings are
 * bucketed, summed (or averaged), and the rollup row is what gets replicated.
 * Direct types skip the pipeline entirely — the raw value is the permanent value.
 *
 * The partition is total and disjoint: every MeasurementType maps to exactly one
 * mode, and a seventh type added without a mapping fails the partition test.
 */
enum ReplicationMode: string
{
    /** Hourly aggregation pipeline — raw → rollup → replicate. */
    case Rollup = 'rollup';

    /** Bypass rollup — raw value is the permanent value. */
    case Direct = 'direct';
}
