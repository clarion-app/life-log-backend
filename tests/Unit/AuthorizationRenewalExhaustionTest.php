<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * FR-017: once a grant can no longer be renewed, retrying it is futile.
 * That is true whether the provider says so directly (AccessRevoked) or
 * says so via a permanently declined refresh — both must mark the
 * connection needs_attention with a reason that tells the user to
 * reconnect, and both must skip the backoff ladder rather than spend five
 * attempts discovering what is already known. A renewal call that merely
 * fails to reach the provider is a different thing entirely and must keep
 * retrying like any other transient failure.
 */
class AuthorizationRenewalExhaustionTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'exhaustion-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    private function runnerWithService(ScriptedSyncService $service): AccountSyncRunner
    {
        $registry = new HealthServiceRegistry();
        $registry->register(ScriptedSyncService::NAME, fn () => $service);

        return new AccountSyncRunner(
            $registry,
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /** @test */
    public function aDirectAccessRevokedMarksTheConnectionUnrenewableWithNoLadder(): void
    {
        $account = $this->makeAccount();

        $service = ScriptedSyncService::withPages([['measurements' => 1]]);
        $service->throwAccessRevokedOn(1);

        Event::fake();

        $this->runnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertSame('authorization_unrenewable', $state->needs_attention_reason);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertNull($state->next_attempt_at);

        $account->refresh();
        $this->assertSame('needs_attention', $account->sync_state);

        Event::assertDispatched(ConnectedAccountNeedsAttention::class);
    }

    /** @test */
    public function aPermanentlyDeclinedRenewalMarksTheConnectionUnrenewableWithNoLadder(): void
    {
        $account = $this->makeAccount();

        $service = ScriptedSyncService::withPages([['measurements' => 1]]);
        $service->throwAccessExpiredOn(1);
        $service->renewAccessDeclines();

        Event::fake();

        $this->runnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertSame('authorization_unrenewable', $state->needs_attention_reason);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertNull($state->next_attempt_at);
        $this->assertSame('access_revoked', $state->last_failure_kind);

        $account->refresh();
        $this->assertSame('needs_attention', $account->sync_state);

        Event::assertDispatched(ConnectedAccountNeedsAttention::class);

        // Exactly one renewal was attempted — a declined renewal is terminal,
        // not something to retry within the same run.
        $this->assertCount(1, $service->renewCalls);
    }

    /** @test */
    public function aMerelyTransientRenewalFailureStillRetriesNormally(): void
    {
        $account = $this->makeAccount();

        $service = ScriptedSyncService::withPages([['measurements' => 1]]);
        $service->throwAccessExpiredOn(1);
        $service->renewAccessThrows(HealthServiceFailure::serviceUnavailable('renewal endpoint down'));

        Event::fake();

        $this->runnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNull($state->needs_attention_reason);
        $this->assertSame(1, $state->consecutive_failures);
        $this->assertNotNull($state->next_attempt_at);
        $this->assertSame('service_unavailable', $state->last_failure_kind);

        $account->refresh();
        $this->assertSame('normal', $account->sync_state);

        Event::assertNotDispatched(ConnectedAccountNeedsAttention::class);
    }
}
