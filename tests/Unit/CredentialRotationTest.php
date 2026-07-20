<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * A secret replacement (FR-004) must not cascade into every existing
 * connection failing identically to an outage. AccountAuthorization stamps
 * the credential_version in force at grant time; a CredentialsRejected that
 * arrives under a version older than the service's current one is precise
 * evidence of what actually happened — reconnect, not "the provider is
 * flaky" — and skips the backoff ladder accordingly (research §10).
 */
class CredentialRotationTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeCredential(int $version, string $secret): ServiceCredential
    {
        return ServiceCredential::create([
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'client-id',
            'client_secret' => $secret,
            'redirect_uri' => 'https://example.com/callback',
            'version' => $version,
        ]);
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'rotation-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(10),
        ]);
    }

    private function makeAuthorization(ConnectedAccount $account, int $credentialVersion): AccountAuthorization
    {
        return AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => CarbonImmutable::now()->addHour(),
            'credential_version' => $credentialVersion,
        ]);
    }

    /**
     * Runner built against a fresh, memoised HealthServiceRegistry — the
     * same one every real sync job resolves its service through, and the
     * instance research §10 warns against reading a credential into once.
     */
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
    public function rejectionUnderAStaleCredentialVersionMarksNeedsAttentionWithoutBurningTheLadder(): void
    {
        $this->makeCredential(2, 'current-secret');
        $account = $this->makeAccount();
        $this->makeAuthorization($account, 1); // granted under the now-superseded version

        $service = ScriptedSyncService::withPages([['measurements' => 1]]);
        $service->throwCredentialsRejectedOn(1);

        // Faked only now — fixture creation above relies on the real event
        // dispatcher to run ConnectedAccount/ServiceCredential's UUID-assigning
        // `creating` listener; Event::fake() replaces that dispatcher.
        Event::fake();

        $this->runnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertSame('credential_rotated', $state->needs_attention_reason);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertNull($state->next_attempt_at);

        $account->refresh();
        $this->assertSame('needs_attention', $account->sync_state);
        $this->assertNull($account->deleted_at); // the connection is not deleted (research §10)

        Event::assertDispatched(ConnectedAccountNeedsAttention::class);
    }

    /** @test */
    public function rejectionAtTheCurrentCredentialVersionFollowsTheOrdinaryLadderUnchanged(): void
    {
        $this->makeCredential(1, 'current-secret');
        $account = $this->makeAccount();
        $this->makeAuthorization($account, 1); // matches the current version

        $service = ScriptedSyncService::withPages([['measurements' => 1]]);
        $service->throwCredentialsRejectedOn(1);

        Event::fake();

        $this->runnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNull($state->needs_attention_reason);
        $this->assertSame(1, $state->consecutive_failures);
        $this->assertNotNull($state->next_attempt_at);
        $this->assertSame('credentials_rejected', $state->last_failure_kind);

        $account->refresh();
        $this->assertSame('normal', $account->sync_state);

        Event::assertNotDispatched(ConnectedAccountNeedsAttention::class);
    }

    /** @test */
    public function theNextCallUsesTheNewSecretAndTheOldOneIsNeverUsedAgain(): void
    {
        $credential = $this->makeCredential(1, 'old-secret');
        $account = $this->makeAccount();
        $this->makeAuthorization($account, 1);

        $credential->update(['client_secret' => 'new-secret', 'version' => 2]);

        // What separates this from an ordinary DB read: forgetScopedInstances()
        // is exactly what the queue worker calls between jobs (QueueServiceProvider),
        // so this reproduces "the next job" rather than "the same PHP object
        // asked twice" — the failure mode research §10 is guarding against.
        $this->app->forgetScopedInstances();

        $current = app(ServiceCredentialProvider::class)->require(ScriptedSyncService::NAME);

        $this->assertSame('new-secret', $current->client_secret);
        $this->assertSame(2, $current->version);
        $this->assertNotSame('old-secret', $current->client_secret);

        // Rotation alone never touches the connection.
        $account->refresh();
        $this->assertSame('normal', $account->sync_state);
        $this->assertNull($account->deleted_at);
    }
}
