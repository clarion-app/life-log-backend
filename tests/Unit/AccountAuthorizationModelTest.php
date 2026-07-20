<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use Carbon\CarbonImmutable;

class AccountAuthorizationModelTest extends TestCase
{
    private function createConnectedAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
        ]);
    }

    /** @test T010 — access_token round-trips through encrypted cast */
    public function accessTokenRoundTripsThroughEncryptedCast(): void
    {
        $account = $this->createConnectedAccount();

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token-abc123',
            'refresh_token' => 'refresh-token-xyz789',
            'credential_version' => 1,
        ]);

        $this->assertSame('access-token-abc123', $auth->access_token);

        $reloaded = AccountAuthorization::find($auth->id);
        $this->assertSame('access-token-abc123', $reloaded->access_token);
    }

    /** @test T010 — refresh_token round-trips through encrypted cast */
    public function refreshTokenRoundTripsThroughEncryptedCast(): void
    {
        $account = $this->createConnectedAccount();

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token-abc123',
            'refresh_token' => 'refresh-token-xyz789',
            'credential_version' => 1,
        ]);

        $this->assertSame('refresh-token-xyz789', $auth->refresh_token);

        $reloaded = AccountAuthorization::find($auth->id);
        $this->assertSame('refresh-token-xyz789', $reloaded->refresh_token);
    }

    /** @test T010 — isUsable() with null expires_at treats as usable */
    public function isUsableWithNullExpiresAtTreatsAsUsable(): void
    {
        $account = $this->createConnectedAccount();

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token',
            'credential_version' => 1,
        ]);

        $this->assertNull($auth->expires_at);
        $this->assertTrue($auth->isUsable());
        $this->assertTrue($auth->isUsable(CarbonImmutable::now()->addYear()));
    }

    /** @test T010 — isUsable() honours $until lookahead */
    public function isUsableHonoursUntilLookahead(): void
    {
        $account = $this->createConnectedAccount();

        $expiresAt = CarbonImmutable::now()->addMinutes(30);

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token',
            'expires_at' => $expiresAt,
            'credential_version' => 1,
        ]);

        // Usable now (within 30 min)
        $this->assertTrue($auth->isUsable());

        // Usable 10 minutes from now (still within 30 min)
        $this->assertTrue($auth->isUsable(CarbonImmutable::now()->addMinutes(10)));

        // Not usable 40 minutes from now (past expiry)
        $this->assertFalse($auth->isUsable(CarbonImmutable::now()->addMinutes(40)));
    }

    /** @test T010 — isUsable() returns false when expired */
    public function isUsableFalseWhenExpired(): void
    {
        $account = $this->createConnectedAccount();

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token',
            'expires_at' => CarbonImmutable::now()->subHour(),
            'credential_version' => 1,
        ]);

        $this->assertFalse($auth->isUsable());
    }

    /** @test T010 — __debugInfo() redacts both tokens */
    public function debugInfoRedactsBothTokens(): void
    {
        $account = $this->createConnectedAccount();

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token-abc123',
            'refresh_token' => 'refresh-token-xyz789',
            'credential_version' => 1,
        ]);

        $debugInfo = $auth->__debugInfo();
        $this->assertSame('[redacted]', $debugInfo['access_token']);
        $this->assertSame('[redacted]', $debugInfo['refresh_token']);
        $this->assertNotSame('access-token-abc123', $debugInfo['access_token']);
        $this->assertNotSame('refresh-token-xyz789', $debugInfo['refresh_token']);
    }

    /** @test T010 — __debugInfo() includes non-sensitive fields */
    public function debugInfoIncludesNonSensitiveFields(): void
    {
        $account = $this->createConnectedAccount();

        $auth = AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'credential_version' => 3,
        ]);

        $debugInfo = $auth->__debugInfo();
        $this->assertArrayHasKey('connected_account_id', $debugInfo);
        $this->assertArrayHasKey('credential_version', $debugInfo);
        $this->assertArrayHasKey('expires_at', $debugInfo);
        $this->assertSame($account->id, $debugInfo['connected_account_id']);
        $this->assertSame(3, $debugInfo['credential_version']);
    }
}
