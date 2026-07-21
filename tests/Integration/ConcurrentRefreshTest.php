<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * T105: N parallel syncs issue EXACTLY ONE refresh request.
 *
 * Real concurrency cannot be produced inside a single PHPUnit process,
 * but the property under test is that TokenRefreshCoordinator serialises
 * refresh calls: the first caller refreshes and persists, subsequent
 * callers re-read and find the token already renewed.
 */
class ConcurrentRefreshTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'concurrent-refresh-user',
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
     * Two sequential sync runs on the same account, both seeing AccessExpired,
     * result in exactly one call to renewAccess. The second run re-reads
     * the authorization and finds it already renewed.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function twoSyncsResultInOneRefresh(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        // Service throws AccessExpired on the first fetch of each run.
        // After the first run refreshes, the second run should NOT refresh again.
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwAccessExpiredOn(1);

        $runner = $this->makeRunnerWithService($service);

        // First run: AccessExpired on fetch → refresh → retry succeeds.
        $result1 = $runner->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Success, $result1->outcome);
        $this->assertCount(1, $service->renewCalls);

        // Second run: authorization is now valid, no AccessExpired.
        // The runner should NOT call renewAccess again.
        $result2 = $runner->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Success, $result2->outcome);

        // Still exactly one refresh call total.
        $this->assertCount(1, $service->renewCalls);
    }

    /**
     * The TokenRefreshCoordinator uses a Cache lock to serialize refresh calls.
     * After the first caller acquires the lock, refreshes, and releases,
     * a second caller that arrives finds the token already renewed.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function coordinatorSerializesRefresh(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwAccessExpiredOn(1);

        $coordinator = app(\ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator::class);

        // First call: token is expired → refresh.
        $result1 = $coordinator->renew($account, $service);
        $this->assertTrue($result1->renewed);
        $this->assertCount(1, $service->renewCalls);

        // Second call: token is now valid → no refresh needed.
        $result2 = $coordinator->renew($account, $service);
        $this->assertTrue($result2->renewed);

        // Still exactly one refresh call.
        $this->assertCount(1, $service->renewCalls);
    }

    /**
     * When renewAccess declines, the coordinator propagates the decline
     * and does not retry.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function declinedRenewalPropagated(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $service->renewAccessDeclines();

        $coordinator = app(\ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator::class);
        $result = $coordinator->renew($account, $service);

        $this->assertFalse($result->renewed);
        $this->assertCount(1, $service->renewCalls);
    }
}
