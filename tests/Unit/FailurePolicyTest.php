<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\FailurePolicy;
use ClarionApp\LifeLogBackend\Sync\FailureResponse;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FailurePolicyTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '11111111-1111-1111-1111-111111111111',
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(30),
        ]);
    }

    private function makeState(ConnectedAccount $account, int $consecutiveFailures = 0): AccountSyncState
    {
        return AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => $consecutiveFailures,
        ]);
    }

    private function policy(): FailurePolicy
    {
        return app(FailurePolicy::class);
    }

    /* ------------------------------------------------------------------
     * ServiceUnavailable walks the ladder
     * ------------------------------------------------------------------ */

    public function testServiceUnavailableWalksLadder(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply($state, HealthServiceFailure::serviceUnavailable(), $now);

        $this->assertTrue($response->countsAsFailure);
        $this->assertFalse($response->flagsImmediately);
        $this->assertFalse($response->clearsCursor);
        $this->assertFalse($response->attemptsRenewal);
        // ladder[0] = 60 minutes
        $this->assertEquals($now->addMinutes(60), $response->nextAttemptAt);
    }

    public function testServiceUnavailableWalksLadderSecondRung(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 1); // pre-increment → index 1
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply($state, HealthServiceFailure::serviceUnavailable(), $now);

        // ladder[1] = 240 minutes
        $this->assertEquals($now->addMinutes(240), $response->nextAttemptAt);
    }

    public function testServiceUnavailableClampsToLastLadderElement(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 10); // beyond ladder length
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply($state, HealthServiceFailure::serviceUnavailable(), $now);

        // ladder[3] = 240 (last element)
        $this->assertEquals($now->addMinutes(240), $response->nextAttemptAt);
    }

    /* ------------------------------------------------------------------
     * RateLimited uses max(ladder[n], retryAfterSeconds) as a floor
     * ------------------------------------------------------------------ */

    public function testRateLimitedUsesLadderWhenHintIsShorter(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        // retryAfterSeconds = 30s, ladder[0] = 60 min → max is 60 min
        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::rateLimited(30),
            $now,
        );

        // FR-018: a rate limit defers the next attempt but is not counted as a
        // connection failure, so it never advances the ladder or flags.
        $this->assertFalse($response->countsAsFailure);
        $this->assertFalse($response->flagsImmediately);
        $this->assertEquals($now->addMinutes(60), $response->nextAttemptAt);
    }

    public function testRateLimitedUsesHintWhenLonger(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        // retryAfterSeconds = 7200s (2h), ladder[0] = 60 min → max is 2h
        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::rateLimited(7200),
            $now,
        );

        $this->assertEquals($now->addSeconds(7200), $response->nextAttemptAt);
    }

    public function testRateLimitedWithNoHint(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::rateLimited(null),
            $now,
        );

        // No hint → fall back to ladder
        $this->assertEquals($now->addMinutes(60), $response->nextAttemptAt);
    }

    /* ------------------------------------------------------------------
     * AccessExpired: requests renewal, counts nothing on success, flags on decline
     * ------------------------------------------------------------------ */

    public function testAccessExpiredAttemptsRenewal(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::accessExpired(),
            $now,
        );

        $this->assertFalse($response->countsAsFailure);
        $this->assertFalse($response->flagsImmediately);
        $this->assertFalse($response->clearsCursor);
        $this->assertTrue($response->attemptsRenewal);
        // On success path, nextAttemptAt is unchanged (null means "retry immediately after renewal")
        $this->assertNull($response->nextAttemptAt);
    }

    /* ------------------------------------------------------------------
     * AccessRevoked: flags immediately, clears cursor, skips the ladder
     * ------------------------------------------------------------------ */

    public function testAccessRevokedFlagsImmediately(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::accessRevoked(),
            $now,
        );

        $this->assertFalse($response->countsAsFailure);
        $this->assertTrue($response->flagsImmediately);
        $this->assertTrue($response->clearsCursor);
        $this->assertFalse($response->attemptsRenewal);
        // Terminal — no next attempt
        $this->assertNull($response->nextAttemptAt);
    }

    /* ------------------------------------------------------------------
     * CredentialsRejected: ladders AND (conceptually) logs at error
     * ------------------------------------------------------------------ */

    public function testCredentialsRejectedWalksLadder(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::credentialsRejected(),
            $now,
        );

        $this->assertTrue($response->countsAsFailure);
        $this->assertFalse($response->flagsImmediately);
        $this->assertFalse($response->clearsCursor);
        $this->assertFalse($response->attemptsRenewal);
        $this->assertEquals($now->addMinutes(60), $response->nextAttemptAt);
    }

    /* ------------------------------------------------------------------
     * InvalidRequest: clears the cursor then ladders
     * ------------------------------------------------------------------ */

    public function testInvalidRequestClearsCursorAndLadders(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 0);
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply(
            $state,
            HealthServiceFailure::invalidRequest(),
            $now,
        );

        $this->assertTrue($response->countsAsFailure);
        $this->assertFalse($response->flagsImmediately);
        $this->assertTrue($response->clearsCursor);
        $this->assertFalse($response->attemptsRenewal);
        $this->assertEquals($now->addMinutes(60), $response->nextAttemptAt);
    }

    /* ------------------------------------------------------------------
     * 5th counted failure flags needs_attention
     * ------------------------------------------------------------------ */

    public function testFifthConsecutiveFailureExceedsMax(): void
    {
        $account = $this->makeAccount();
        $state = $this->makeState($account, 4); // 4 failures already, this is the 5th
        $now = CarbonImmutable::now();

        $response = $this->policy()->apply($state, HealthServiceFailure::serviceUnavailable(), $now);

        // The 5th counted failure should flag immediately (reaching sync_max_consecutive_failures = 5)
        $this->assertTrue($response->countsAsFailure);
        $this->assertTrue($response->flagsImmediately);
    }
}
