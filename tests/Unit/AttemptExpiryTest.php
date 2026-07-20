<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Tests\TestCase;

class AttemptExpiryTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);

        $this->actingAs($this->user);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test */
    public function attemptOlderThanOneHourRefusedAtVerificationTime()
    {
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => 'fake-step',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour()->subMinute(), // Expired > 1h ago
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'connection_not_completed');

        // No connection created
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function attemptWithinOneHourIsAccepted()
    {
        // This test verifies that an attempt that is not yet expired is not
        // refused on expiry grounds. The actual completion depends on the
        // service being configured and the state matching.
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour()->subMinute(), // Still valid
        ]);

        $this->assertTrue($attempt->isUsable());
    }

    /** @test */
    public function isUsableReturnsFalseWhenExpired()
    {
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse($attempt->isUsable());
    }

    /** @test */
    public function prunerNotRunningDoesNotMakeExpiredAttemptUsable()
    {
        // Even if the pruner has not run, an expired attempt must be refused.
        // This is the key security property: expiry is checked at verification,
        // not only by the pruning command.
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => 'fake-step',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $state = base64_encode(random_bytes(32));
        // Create an expired attempt (the pruner hasn't run, so it still exists)
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }
}
