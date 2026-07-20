<?php

namespace ClarionApp\LifeLogBackend\Services;

use ClarionApp\LifeLogBackend\Models\UnmappedTypeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what a service reported that could not be mapped, so a maintainer can
 * decide whether to extend the vocabulary.
 *
 * Never throws (FR-018). This sits on the per-item translation hot path, and a
 * diagnostic write failing must not cost the caller the measurements it did map
 * successfully — so the whole body is wrapped and a failure is logged and
 * swallowed.
 */
class UnmappedTypeRecorder
{
    /**
     * Record one sighting of an unmappable type or unit.
     *
     * The first sighting inserts and logs; later sightings increment
     * occurrence_count and move last_seen_at, leaving first_seen_at alone.
     */
    public function record(
        string $externalService,
        string $serviceTypeName,
        ?string $sampleValue = null,
        ?string $sampleUnit = null,
    ): void {
        try {
            $now = CarbonImmutable::now();

            // One statement so concurrent translations cannot lose an increment
            // or race each other into two rows for the same pair.
            $inserted = DB::table((new UnmappedTypeRecord())->getTable())->insertOrIgnore([
                'external_service' => $externalService,
                'service_type_name' => $serviceTypeName,
                'sample_value' => $sampleValue,
                'sample_unit' => $sampleUnit,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'occurrence_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted > 0) {
                Log::info(
                    "Unmapped health type recorded: service '{$externalService}', type '{$serviceTypeName}'"
                    . ' — no vocabulary equivalent, measurement skipped.'
                );

                return;
            }

            $update = [
                'last_seen_at' => $now,
                'updated_at' => $now,
                'occurrence_count' => DB::raw('occurrence_count + 1'),
            ];

            // Refresh the sample only when this sighting carried one — a later
            // sighting without a sample must not erase the one already stored.
            if ($sampleValue !== null) {
                $update['sample_value'] = $sampleValue;
            }

            if ($sampleUnit !== null) {
                $update['sample_unit'] = $sampleUnit;
            }

            DB::table((new UnmappedTypeRecord())->getTable())
                ->where('external_service', $externalService)
                ->where('service_type_name', $serviceTypeName)
                ->update($update);
        } catch (Throwable $e) {
            Log::warning(
                "Failed to record unmapped health type '{$serviceTypeName}' from '{$externalService}': "
                . $e->getMessage()
            );
        }
    }
}
