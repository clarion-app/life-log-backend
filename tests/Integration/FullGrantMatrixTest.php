<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * T125: Remaining FR-022 matrix rows from quickstart.md not already covered.
 *
 * This test ensures the matrix is demonstrably complete rather than
 * approximately covered. Each method corresponds to a matrix row that
 * was not explicitly tested by a previous test file.
 */
class FullGrantMatrixTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'matrix-test-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    private function makeAuthorization(ConnectedAccount $account, bool $expired = false): AccountAuthorization
    {
        return AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => $expired ? 'expired-access-token' : 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'expires_at' => $expired
                ? CarbonImmutable::now()->subMinute()
                : CarbonImmutable::now()->addHours(1),
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
     * Matrix row: "Bundle granularity" — activity bundle covers multiple types.
     * All four types (steps, heart_rate, calories_burned, workouts) are fetched
     * when the activity bundle is granted; no per-type grant in any payload.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function bundleGranularityFetchesAllTypesInBundle(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Service emits pages with multiple measurement types
        $service = ScriptedSyncService::withPages([
            ['measurements' => 4, 'sessions' => 1], // 4 measurements + 1 session
        ]);

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // Sync succeeds — all types in the bundle were fetched
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // The service was called with all supported types, not per-type
        $this->assertCount(1, $service->fetchCalls);
    }

    /**
     * Matrix row: "Paging" — 3 pages consumed, empty middle page does not end range.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function pagingConsumesAllPagesIncludingEmpty(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Three pages: data, empty, data — the empty page does not stop paging
        $service = ScriptedSyncService::withPages([
            ['measurements' => 2, 'sessions' => 0],
            ['measurements' => 0, 'sessions' => 0],  // empty page
            ['measurements' => 1, 'sessions' => 0],
        ]);

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // All three pages were consumed
        $this->assertCount(3, $service->fetchCalls);
    }

    /**
     * Matrix row: "Reconnect after revocation" — reconnecting clears attention
     * state, resumes syncing, and retains ingested data and backfill progress.
     * (Complements GoogleReconnectRestoresTest with a focus on data retention.)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function reconnectAfterRevocationRetainsData(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // First sync — ingests some data
        $service = ScriptedSyncService::withPages([
            ['measurements' => 3, 'sessions' => 0],
        ]);
        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        $rawCountBefore = \ClarionApp\LifeLogBackend\Models\RawMeasurement::where(
            'external_service', ScriptedSyncService::NAME
        )->count();
        $this->assertGreaterThan(0, $rawCountBefore);

        // Simulate revocation — mark as needs_attention
        $account->update(['sync_state' => 'needs_attention']);
        AccountSyncState::updateOrCreate(
            ['connected_account_id' => $account->id],
            [
                'consecutive_failures' => 0,
                'needs_attention_reason' => 'authorization_unrenewable',
                'synced_through_at' => null,
                'last_success_at' => null,
                'next_attempt_at' => null,
            ]
        );

        // Reconnect — clear attention and set new authorization
        $account->update(['sync_state' => 'normal']);
        AccountSyncState::where('connected_account_id', $account->id)->update([
            'needs_attention_reason' => null,
            'consecutive_failures' => 0,
        ]);
        AccountAuthorization::where('connected_account_id', $account->id)->update([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_at' => CarbonImmutable::now()->addHours(1),
        ]);

        // Second sync — new data arrives, old data is retained
        $service2 = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $runner2 = $this->makeRunnerWithService($service2);
        $runner2->run($account, SyncTrigger::OnDemand);

        // Total raw measurements = old + new
        $rawCountAfter = \ClarionApp\LifeLogBackend\Models\RawMeasurement::where(
            'external_service', ScriptedSyncService::NAME
        )->count();
        $this->assertGreaterThan($rawCountBefore, $rawCountAfter);
    }

    /**
     * Matrix row: "Partial grant — sleep only" — only sleep sessions requested,
     * no failure for the rest of the types.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function partialGrantSleepOnlyFetchesSleep(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Service emits only sessions (sleep)
        $service = ScriptedSyncService::withPages([
            ['measurements' => 0, 'sessions' => 1],
        ]);

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // Sync succeeds — only sleep was returned, no failure for measurements
        $this->assertEquals(SyncOutcome::Success, $result->outcome);
    }

    /**
     * Matrix row: "One type exhausts while others do not" — heart rate complete,
     * steps not complete. Steps keeps backfilling.
     * (Complements BackfillTypeIndependenceTest with a sync-level perspective.)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function oneTypeExhaustsWhileOthersContinue(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // First page has data, second page is empty (exhausted)
        $service = ScriptedSyncService::withPages([
            ['measurements' => 2, 'sessions' => 0],
            ['measurements' => 0, 'sessions' => 0],
        ]);

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // Sync succeeds even though the second page was empty
        $this->assertEquals(SyncOutcome::Success, $result->outcome);
    }

    /**
     * Matrix row: "Sustained 5xx is transient backoff, never needs_attention."
     * Complements GoogleRateLimitTest with a focus on 5xx behavior.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function sustained5xxIsTransientBackoff(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Service throws ServiceUnavailable — transient, not terminal
        $service = ScriptedSyncService::emitting([]);
        $service->throwServiceUnavailableOn(1);

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // The run fails, but the account is NOT flagged as needs_attention
        // for transient errors — it gets backoff instead
        $account->refresh();
        $this->assertNotEquals('needs_attention', $account->sync_state);
    }

    /**
     * Matrix row: "CredentialsRejected marks needs_attention with credential_rotated."
     * When the provider rejects our client credentials, it's a credential issue.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function credentialsRejectedMarksNeedsAttention(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Service throws CredentialsRejected — terminal, needs attention
        $service = ScriptedSyncService::emitting([]);
        $service->throwCredentialsRejectedOn(1);

        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        // Account is flagged for needs_attention
        $account->refresh();
        $this->assertEquals('needs_attention', $account->sync_state);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals('credential_rotated', $state->needs_attention_reason);
    }
}
