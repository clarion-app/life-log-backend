<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CallbackForgeryTest extends TestCase
{
    protected $user;
    protected $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);

        $this->otherUser = \ClarionApp\Backend\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'hashed',
        ]);

        // Configure a credential
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => 'fake-step',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $this->actingAs($this->user);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /**
     * Helper to get the uniform 422 response body.
     */
    protected function uniformErrorBody(): array
    {
        return ['error' => 'connection_not_completed'];
    }

    /** @test */
    public function forgedStateReturns422()
    {
        // Create an attempt with a valid state
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // Submit a forged state
        $forgedState = base64_encode(random_bytes(32));
        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $forgedState,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function replayedStateReturns422()
    {
        // Create an attempt and consume it
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
            'consumed_at' => now(),
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function expiredAttemptReturns422()
    {
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour(), // Expired
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function wrongUserReturns422()
    {
        // Create an attempt for the other user
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->otherUser->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // User A tries to complete user B's attempt
        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function mismatchedServiceReturns422()
    {
        // Create an attempt for fake-step
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // Submit with mismatched service
        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-span',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function mismatchedRedirectUriReturns422()
    {
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // Submit with mismatched redirect_uri
        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://evil.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);
    }

    /** @test */
    public function providerDenialReturns422AndConsumesAttempt()
    {
        // Register a service that throws CredentialsRejected on completeConnection
        $scriptedService = \Tests\Support\ScriptedSyncService::emitting([]);
        $scriptedService->completeConnectionThrowsCredentialsRejected();

        app(\ClarionApp\LifeLogBackend\External\HealthServiceRegistry::class)->register(
            \Tests\Support\ScriptedSyncService::NAME,
            fn () => $scriptedService,
        );

        // Configure credential for the scripted service
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => \Tests\Support\ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => \Tests\Support\ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => \Tests\Support\ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson($this->uniformErrorBody());
        $this->assertDatabaseCount('life_log_connected_accounts', 0);

        // Provider denial still consumes the attempt (FR-015)
        $attempt->refresh();
        $this->assertNotNull($attempt->consumed_at);
    }

    /** @test */
    public function allErrorsAreByteIdentical()
    {
        // Collect all 422 response bodies
        $bodies = [];

        // 1. Forged state
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $r1 = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => base64_encode(random_bytes(32)), // forged
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);
        $bodies[] = $r1->getContent();

        // 2. Expired attempt
        $state2 = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state2),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour(),
        ]);

        $r2 = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state2,
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);
        $bodies[] = $r2->getContent();

        // 3. Mismatched redirect_uri
        $state3 = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state3),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $r3 = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => 'fake-step',
            'state' => $state3,
            'code' => 'some-code',
            'redirect_uri' => 'https://evil.com/callback',
        ]);
        $bodies[] = $r3->getContent();

        // All bodies should be identical
        foreach ($bodies as $body) {
            $this->assertEquals(json_encode(['error' => 'connection_not_completed']), $body);
        }
    }
}
