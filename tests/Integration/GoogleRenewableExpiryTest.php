<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * T104: 401 then successful refresh ⇒ renewed silently, same cursor retried.
 */
class GoogleRenewableExpiryTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'renewable-expiry-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    private function makeExpiredAuthorization(ConnectedAccount $account): AccountAuthorization
    {
        return AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'stale-access-token',
            'refresh_token' => 'stale-refresh-token',
            'expires_at' => CarbonImmutable::now()->subMinute(),
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
     * When a fetch throws AccessExpired but renewAccess succeeds,
     * the runner retries the same cursor and the run succeeds.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function accessExpiredThenRefreshSucceeds(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        // First fetch throws AccessExpired, second fetch succeeds after refresh.
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwAccessExpiredOn(1);
        // renewAccess succeeds by default.

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // renewAccess was called exactly once.
        $this->assertCount(1, $service->renewCalls);

        // Account is NOT flagged for needs_attention.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);
    }

    /**
     * After a successful refresh, the runner retries with the same window
     * (cursor) — it does not advance or clear.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function cursorRetriedAfterRefresh(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwAccessExpiredOn(1);

        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        // Two fetch calls: first threw AccessExpired, second succeeded.
        $this->assertCount(2, $service->fetchCalls);

        // Both calls had the same since/until window.
        $firstCall = $service->fetchCalls[0];
        $secondCall = $service->fetchCalls[1];

        $this->assertEquals($firstCall['since'], $secondCall['since']);
        $this->assertEquals($firstCall['until'], $secondCall['until']);
    }

    /**
     * A successful refresh updates the authorization row with a new expiry.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function authorizationUpdatedAfterRefresh(): void
    {
        $account = $this->makeAccount();
        $oldAuth = $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwAccessExpiredOn(1);

        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        // The authorization was updated with a new expiry.
        $newAuth = AccountAuthorization::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($newAuth);

        // New expiry is in the future (not the old expired value).
        $this->assertTrue(
            $newAuth->expires_at->greaterThan(CarbonImmutable::now()),
            'Authorization expiry should be in the future after refresh',
        );
    }
}
