<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class InvalidRangeTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '66666666-6666-6666-6666-666666666666',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => $connectedAt,
        ]);
    }

    private function registerScriptedService(ScriptedSyncService $service): void
    {
        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $service,
        );
    }

    private function makeRunner(): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            app(HealthServiceRegistry::class),
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /* ------------------------------------------------------------------
     * T028: until <= since → skipped, service never called, consecutive_failures untouched
     * ------------------------------------------------------------------ */

    public function test_invalidRangeReturnsSkipped(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // Create a sync state where synced_through_at is in the future relative to now,
        // so the overlap window produces until <= since
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'synced_through_at' => CarbonImmutable::now()->addDays(1), // Future!
        ]);
        $originalFailures = $state->consecutive_failures;

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);

        // Outcome should be skipped
        $this->assertEquals(SyncOutcome::Skipped, $result->outcome);

        // Service should never have been called
        $this->assertCount(0, $service->fetchCalls, 'Service fetch should not be called for empty window');

        // consecutive_failures should be untouched
        $state->refresh();
        $this->assertEquals(
            $originalFailures,
            $state->consecutive_failures,
            'consecutive_failures should not change on skipped',
        );
    }

    public function test_skippedDoesNotMutateAnyFailureState(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // State with some existing failures and a gate
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'synced_through_at' => CarbonImmutable::now()->addDays(1),
            'consecutive_failures' => 3,
            'next_attempt_at' => CarbonImmutable::now()->addHours(2),
            'last_failure_at' => CarbonImmutable::now()->subHours(1),
            'last_failure_kind' => 'service_unavailable',
        ]);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Skipped, $result->outcome);

        // All failure state should be unchanged
        $state->refresh();
        $this->assertEquals(3, $state->consecutive_failures);
        $this->assertNotNull($state->next_attempt_at);
        $this->assertEquals('service_unavailable', $state->last_failure_kind);
    }
}
