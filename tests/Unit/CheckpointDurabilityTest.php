<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncResult;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class CheckpointDurabilityTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '44444444-4444-4444-4444-444444444444',
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
     * T026: Writer throws mid-page → synced_through_at AND cursor unmoved;
     *        finalize sets synced_through_at to run-start until, never now()
     * ------------------------------------------------------------------ */

    public function test_writerThrowMidPageLeavesCheckpointUnmoved(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // Pre-create a sync state with a known checkpoint
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'synced_through_at' => CarbonImmutable::now()->subDays(1),
        ]);
        $checkpointBefore = $state->synced_through_at;

        // Service emits 2 pages; page 2 will cause a write failure
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        // Make the DB throw on the second page write by injecting a constraint
        // We'll use a mock measurement writer that throws on the second call.
        $throwingWriter = new class extends \ClarionApp\LifeLogBackend\Services\RawMeasurementWriter {
            public int $callCount = 0;
            public function write(array $readings): int
            {
                $this->callCount++;
                if ($this->callCount === 2) {
                    throw new \RuntimeException('Simulated write failure');
                }
                return parent::write($readings);
            }
        };

        $runner = new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            app(HealthServiceRegistry::class),
            $throwingWriter,
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );

        // The runner should catch the exception as a failure (not propagate it),
        // because it wraps the page loop in try/catch for HealthServiceFailure.
        // A generic RuntimeException from the writer should propagate though.
        // So we expect the exception to propagate and the checkpoint to be unchanged.
        try {
            $runner->run($account, SyncTrigger::Scheduled);
            // If we reach here, the runner swallowed the exception — that's fine
            // for the checkpoint assertion.
        } catch (\Throwable $e) {
            // Expected: the exception propagates
        }

        // Refresh state from DB
        $state->refresh();

        // synced_through_at should be unchanged (still the pre-existing checkpoint)
        $this->assertEquals(
            $checkpointBefore->toDateTimeString(),
            $state->synced_through_at?->toDateTimeString(),
            'Checkpoint should not advance when a page write fails',
        );

        // Cursor from page 0 should be persisted (page 0 transaction committed),
        // but page 1's cursor should not be (transaction rolled back).
        $this->assertEquals('cursor-1', $state->cursor, 'Cursor from page 0 should be persisted');
    }

    public function test_finalizeSetsSyncedThroughAtToRunStartUntil(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(10);
        $account = $this->makeAccount($connectedAt);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);

        // synced_through_at should equal the window's until (the run-start value),
        // not a later "now()" at finalize time.
        // The window's until is captured at run start and passed to each fetch call.
        $firstCallUntil = $service->fetchCalls[0]['until'];
        // Compare timestamps to avoid microsecond precision differences
        $this->assertEquals(
            CarbonImmutable::parse($firstCallUntil)->getTimestamp(),
            $state->synced_through_at->getTimestamp(),
            'synced_through_at should be the run-start until, not now() at finalize',
        );
    }
}
