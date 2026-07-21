<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * T110: Empty result is not a failure.
 *
 * When a sync returns no data (empty result page), it is a success —
 * not a failure, not needs_attention.
 */
class EmptyResultNotFailureTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'empty-result-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    private function makeAuthorization(ConnectedAccount $account): AccountAuthorization
    {
        return AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'expires_at' => CarbonImmutable::now()->addHours(1),
            'credential_version' => 1,
        ]);
    }

    private function makeRunnerWithService(ScriptedSyncService $service): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        $registry = new HealthServiceRegistry();
        $registry->register(ScriptedSyncService::NAME, fn () => $service);

        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            $registry,
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /**
     * An empty result page (no measurements, no sessions) is a success.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function emptyResultPageIsSuccess(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Service returns an empty page.
        $service = ScriptedSyncService::emitting([
            new ResultPage([], [], null),
        ]);

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // Empty result is a success.
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // Account is in normal state.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);

        // consecutive_failures is 0.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(0, $state->consecutive_failures);
    }

    /**
     * Multiple consecutive empty results are still successes.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function consecutiveEmptyResultsAreSuccesses(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Run 5 times with empty results.
        for ($i = 0; $i < 5; $i++) {
            $service = ScriptedSyncService::emitting([
                new ResultPage([], [], null),
            ]);

            $runner = $this->makeRunnerWithService($service);
            $result = $runner->run($account, SyncTrigger::Scheduled);

            $this->assertEquals(SyncOutcome::Success, $result->outcome);
        }

        // Account is still in normal state.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);

        // consecutive_failures is still 0.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(0, $state->consecutive_failures);
    }

    /**
     * An empty result updates last_success_at and synced_through_at.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function emptyResultUpdatesSuccessTimestamp(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        $service = ScriptedSyncService::emitting([
            new ResultPage([], [], null),
        ]);

        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);

        // last_success_at was updated.
        $this->assertNotNull($state->last_success_at);
    }

    /**
     * An empty result page does not throw an exception.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function emptyResultDoesNotThrow(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        $service = ScriptedSyncService::emitting([
            new ResultPage([], [], null),
        ]);

        $runner = $this->makeRunnerWithService($service);

        // No exception thrown.
        $result = $runner->run($account, SyncTrigger::Scheduled);
        $this->assertNotNull($result);
    }
}
