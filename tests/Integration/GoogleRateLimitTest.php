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
 * T108: 429 with/without Retry-After ⇒ RateLimited, never needs_attention.
 *
 * Rate limiting is a transient failure — the account should be backed off
 * but never flagged for needs_attention.
 */
class GoogleRateLimitTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'rate-limit-user',
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
     * A 429 response with Retry-After header should back off but not flag
     * the account for needs_attention.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function rateLimitedWithRetryAfterDoesNotFlagNeedsAttention(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $service->throwRateLimitedOn(1, 60); // 60 seconds retry-after

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // The run fails (rate limited).
        $this->assertEquals(SyncOutcome::Failure, $result->outcome);

        // Account is NOT flagged for needs_attention.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);

        // next_attempt_at is set for backoff.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertNull($state->needs_attention_reason);
        $this->assertNotNull($state->next_attempt_at);
    }

    /**
     * A 429 response without Retry-After should use default backoff
     * but still not flag needs_attention.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function rateLimitedWithoutRetryAfterDoesNotFlagNeedsAttention(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $service->throwRateLimitedOn(1); // No retry-after

        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // The run fails (rate limited).
        $this->assertEquals(SyncOutcome::Failure, $result->outcome);

        // Account is NOT flagged for needs_attention.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);

        // next_attempt_at is set for default backoff.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertNull($state->needs_attention_reason);
        $this->assertNotNull($state->next_attempt_at);
    }

    /**
     * Rate limiting is a yield, not a connection failure (FR-018).
     *
     * It must not advance the backoff ladder: five rate limits in a row are
     * five requests to come back later, and an instance busy enough to hit the
     * quota five times would otherwise talk itself into asking the user to
     * reconnect a connection that was healthy throughout.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function repeatedRateLimitingDoesNotTriggerNeedsAttention(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Cause 5 rate limit failures.
        for ($i = 0; $i < 5; $i++) {
            $service = ScriptedSyncService::emitting([]);
            $service->throwRateLimitedOn(1, 60);

            $runner = $this->makeRunnerWithService($service);
            $runner->run($account, SyncTrigger::Scheduled);
        }

        // Account is still in normal state (not needs_attention).
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);

        // The ladder never moved.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(0, $state->consecutive_failures);
        $this->assertNull($state->needs_attention_reason);
        $this->assertNotNull($state->next_attempt_at);
    }

    /**
     * A rate limit leaves the ladder where it was, and a later success keeps
     * it there and clears the deferral.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function successAfterRateLimitingResetsFailures(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // First: rate limit.
        $service = ScriptedSyncService::emitting([]);
        $service->throwRateLimitedOn(1, 60);

        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(0, $state->consecutive_failures);

        // Second: success.
        $service2 = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);

        $runner2 = $this->makeRunnerWithService($service2);
        $runner2->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(0, $state->consecutive_failures);
        $this->assertNull($state->next_attempt_at);
    }
}
