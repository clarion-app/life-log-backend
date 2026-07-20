<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * A sync run and a user action can both hit AccessExpired for the same
 * account at nearly the same time. TokenRefreshCoordinator must serialise
 * them: exactly one call reaches the provider, and the loser observes the
 * winner's result rather than refreshing again (research §9).
 *
 * Real concurrency cannot be produced inside a single PHPUnit process, but
 * the property under test does not require it — it requires that a second
 * caller, arriving after the first has already refreshed and released the
 * lock, re-reads the authorization and finds it already usable. That is
 * exactly what two sequential calls exercise.
 */
class TokenRefreshRaceTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'race-user',
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

    /** @test */
    public function twoContendingRefreshesResultInExactlyOneProviderCall(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $coordinator = new TokenRefreshCoordinator();

        // First contender: the stored authorization is expired, so this call
        // must reach the provider.
        $first = $coordinator->renew($account, $service);
        $this->assertTrue($first->renewed);
        $this->assertCount(1, $service->renewCalls);

        // Second contender, simulating one that arrived after the first
        // already refreshed and persisted: the re-read under the lock finds
        // a usable token and must not call the provider again.
        $second = $coordinator->renew($account, $service);
        $this->assertTrue($second->renewed);
        $this->assertCount(1, $service->renewCalls);

        // Both observed the same final token lifetime.
        $this->assertNotNull($first->expiresAt);
        $this->assertEquals(
            $first->expiresAt->toDateTimeString(),
            $second->expiresAt->toDateTimeString(),
        );

        // No stale write survives — the persisted row reflects the one
        // successful refresh, not a token the provider has already
        // consumed by the time a second caller would have presented it.
        $authorization = AccountAuthorization::where('connected_account_id', $account->id)->first();
        $this->assertEquals(
            $first->expiresAt->toDateTimeString(),
            $authorization->expires_at->toDateTimeString(),
        );
    }

    /** @test */
    public function aTimedOutWaitFailsClosedRatherThanRefreshingUnsynchronised(): void
    {
        $account = $this->makeAccount();
        $this->makeExpiredAuthorization($account);

        $service = ScriptedSyncService::emitting([]);
        $coordinator = new TokenRefreshCoordinator();

        $lock = \Illuminate\Support\Facades\Cache::lock(
            sprintf('life-log:token-refresh:%s', $account->id),
            30,
        );
        $this->assertTrue($lock->get());

        try {
            config(['life-log.token_refresh_wait_seconds' => 0]);

            $this->expectException(\ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure::class);
            $coordinator->renew($account, $service);
        } finally {
            $lock->release();
        }

        // The provider was never reached while the lock was held elsewhere.
        $this->assertCount(0, $service->renewCalls);
    }
}
