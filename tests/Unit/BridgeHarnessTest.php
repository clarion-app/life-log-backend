<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\HealthMetric;

class BridgeHarnessTest extends TestCase
{
    /** @test */
    public function recordingBridgeCapturesPublishForDirectlySavedHealthMetric(): void
    {
        $chain = $this->enableRecordingBridge();

        $metric = new HealthMetric();
        $metric->user_id = '00000000-0000-0000-0000-000000000001';
        $metric->type = 'test_metric';
        $metric->value = 100.0;
        $metric->recorded_at = now();
        $metric->save();

        $this->assertCount(1, $chain->published);
        $this->assertEquals('life_log_health_metrics', $chain->published[0]['stream']);
    }
}
