<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Illuminate\Console\Command;

/**
 * Projects the vocabulary's aggregation rules into the classification table the
 * hourly rollup already reads.
 *
 * The table stays the rollup's single lookup — the rollup itself is unchanged.
 * Manual free-form types absent from the vocabulary keep no row here and keep
 * deferring as 'unclassified_type', exactly as before.
 */
class SyncVocabularyClassificationsCommand extends Command
{
    protected $signature = 'life-log:sync-vocabulary';
    protected $description = 'Project the measurement vocabulary into the classification table used by the rollup';

    public function handle(): int
    {
        $existing = MeasurementTypeClassification::pluck('aggregation', 'type')->all();

        $created = 0;
        $updated = 0;

        foreach (MeasurementType::cases() as $type) {
            $aggregation = $type->aggregation();
            $current = $existing[$type->value] ?? null;

            // A row that already agrees is left completely alone — not even its
            // timestamps move, so running this repeatedly is a true no-op.
            if ($current === $aggregation) {
                continue;
            }

            if ($current === null) {
                MeasurementTypeClassification::create([
                    'type' => $type->value,
                    'aggregation' => $aggregation,
                ]);
                $created++;

                continue;
            }

            MeasurementTypeClassification::where('type', $type->value)
                ->update(['aggregation' => $aggregation]);
            $updated++;
        }

        $this->info("Vocabulary sync complete: {$created} created, {$updated} corrected, "
            . (count(MeasurementType::cases()) - $created - $updated) . ' unchanged.');

        return self::SUCCESS;
    }
}
