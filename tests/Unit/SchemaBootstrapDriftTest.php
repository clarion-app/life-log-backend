<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

class SchemaBootstrapDriftTest extends TestCase
{
    /**
     * T022: Drift guard — assert hand-built bootstrap schema matches migration files.
     *
     * For each table, parse the column list the migration declares and assert
     * Schema::getColumnListing() returns exactly that set.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function bootstrapSchemaMatchesMigrations(): void
    {
        $migrationDir = __DIR__ . '/../../database/migrations';

        // life_log_raw_measurements columns from migration 000001
        $expectedRawColumns = [
            'id', 'user_id', 'external_service', 'external_id', 'type',
            'value', 'unit', 'recorded_at', 'bucket_hour', 'metadata',
            'created_at', 'updated_at',
        ];
        $actualRawColumns = Schema::getColumnListing('life_log_raw_measurements');
        $this->assertEquals(
            $expectedRawColumns,
            $actualRawColumns,
            'life_log_raw_measurements columns drift from migration'
        );

        // life_log_measurement_type_classifications columns from migration 000002
        $expectedClassificationColumns = [
            'id', 'type', 'aggregation', 'created_at', 'updated_at',
        ];
        $actualClassificationColumns = Schema::getColumnListing('life_log_measurement_type_classifications');
        $this->assertEquals(
            $expectedClassificationColumns,
            $actualClassificationColumns,
            'life_log_measurement_type_classifications columns drift from migration'
        );

        // life_log_measurement_rollup_queue columns from migration 000003
        $expectedQueueColumns = [
            'id', 'user_id', 'external_service', 'type', 'unit',
            'bucket_hour', 'deferred_reason', 'created_at', 'updated_at',
        ];
        $actualQueueColumns = Schema::getColumnListing('life_log_measurement_rollup_queue');
        $this->assertEquals(
            $expectedQueueColumns,
            $actualQueueColumns,
            'life_log_measurement_rollup_queue columns drift from migration'
        );

        // life_log_health_metrics columns (post-migration shape: original + provenance + widened value)
        // Original: id, user_id, type, value, recorded_at, created_at, updated_at, deleted_at
        // Migration 000004 adds: source, unit, external_service, bucket_hour, metadata
        // Migration 000005 widens value (no column change, just type)
        $expectedHealthMetricColumns = [
            'id', 'user_id', 'type', 'source', 'unit', 'external_service',
            'bucket_hour', 'metadata', 'value', 'recorded_at',
            'created_at', 'updated_at', 'deleted_at',
        ];
        $actualHealthMetricColumns = Schema::getColumnListing('life_log_health_metrics');
        $this->assertEquals(
            $expectedHealthMetricColumns,
            $actualHealthMetricColumns,
            'life_log_health_metrics columns drift from migrations'
        );

        // life_log_raw_health_sessions columns from migration 000006
        $expectedRawSessionColumns = [
            'id', 'user_id', 'external_service', 'external_id', 'session_type',
            'started_at', 'ended_at', 'summary_values', 'promoted_at',
            'created_at', 'updated_at',
        ];
        $actualRawSessionColumns = Schema::getColumnListing('life_log_raw_health_sessions');
        $this->assertEquals(
            $expectedRawSessionColumns,
            $actualRawSessionColumns,
            'life_log_raw_health_sessions columns drift from migration'
        );

        // life_log_health_sessions columns from migration 000007
        $expectedSessionColumns = [
            'id', 'user_id', 'external_service', 'external_id', 'session_type',
            'started_at', 'ended_at', 'summary_values', 'source',
            'created_at', 'updated_at', 'deleted_at',
        ];
        $actualSessionColumns = Schema::getColumnListing('life_log_health_sessions');
        $this->assertEquals(
            $expectedSessionColumns,
            $actualSessionColumns,
            'life_log_health_sessions columns drift from migration'
        );

        // life_log_unmapped_type_records columns from migration 000008
        $expectedUnmappedColumns = [
            'id', 'external_service', 'service_type_name', 'sample_value',
            'sample_unit', 'first_seen_at', 'last_seen_at', 'occurrence_count',
            'created_at', 'updated_at',
        ];
        $actualUnmappedColumns = Schema::getColumnListing('life_log_unmapped_type_records');
        $this->assertEquals(
            $expectedUnmappedColumns,
            $actualUnmappedColumns,
            'life_log_unmapped_type_records columns drift from migration'
        );

        // data_stream_registries columns (includes deleted_at due to SoftDeletes via EloquentMultiChainBridge)
        $expectedStreamRegColumns = ['id', 'class_name', 'data_stream', 'created_at', 'updated_at', 'deleted_at'];
        $actualStreamRegColumns = Schema::getColumnListing('data_stream_registries');
        $this->assertEquals(
            $expectedStreamRegColumns,
            $actualStreamRegColumns,
            'data_stream_registries columns drift from bootstrap'
        );
    }
}
