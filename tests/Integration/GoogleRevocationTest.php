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
 * T103: Refresh → invalid_grant yields declined RenewalResult, not exception;
 * runner routes to AccessRevoked ⇒ needs_attention, reason authorization_unrenewable.
 */
class GoogleRevocationTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'revocation-user',
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
     * When the token endpoint returns invalid_grant on refresh, the runner
     * routes to AccessRevoked ⇒ needs_attention with authorization_unrenewable.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function refreshInvalidGrantYieldsNeedsAttention(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        // Service throws AccessExpired on first fetch, then renewAccess declines.
        $service = ScriptedSyncService::emitting([]);
        $service->throwAccessExpiredOn(1);
        $service->renewAccessDeclines();

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // The run outcome is Failure (access revoked is terminal for this run).
        $this->assertEquals(SyncOutcome::Failure, $result->outcome);

        // renewAccess was called exactly once.
        $this->assertCount(1, $service->renewCalls);

        // Account is flagged for needs_attention.
        $account->refresh();
        $this->assertEquals('needs_attention', $account->sync_state);

        // Sync state has the attention reason.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals('authorization_unrenewable', $state->needs_attention_reason);

        // Cursor is cleared on revocation.
        $this->assertNull($state->synced_through_at);
    }

    /**
     * A declined renewal does NOT throw an exception — it returns a
     * FailureResponse that flags the account immediately.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function declinedRenewalDoesNotThrow(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $service->throwAccessExpiredOn(1);
        $service->renewAccessDeclines();

        $runner = $this->makeRunnerWithService($service);

        // No exception thrown — the runner handles the declined renewal gracefully.
        $result = $runner->run($account, SyncTrigger::Scheduled);
        $this->assertNotNull($result);
    }

    /**
     * The needs_attention_reason is distinct from sync_failures — revocation
     * is authorization_unrenewable, not a transient failure.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function revocationReasonDistinctFromSyncFailures(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $service->throwAccessExpiredOn(1);
        $service->renewAccessDeclines();

        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);

        // authorization_unrenewable is NOT sync_failures.
        $this->assertNotEquals('sync_failures', $state->needs_attention_reason);
        $this->assertEquals('authorization_unrenewable', $state->needs_attention_reason);
    }
}
