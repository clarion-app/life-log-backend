<?php

namespace Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A user acting on another user's connection must be refused with a
 * response indistinguishable from one for a connection that does not
 * exist at all — 404, never 403 (FR-024).
 */
class CrossUserAccessDenialTest extends TestCase
{
    protected User $userA;
    protected User $userB;
    protected ConnectedAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User A',
            'email' => 'a@example.com',
            'password' => 'hashed',
        ]);

        $this->userB = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User B',
            'email' => 'b@example.com',
            'password' => 'hashed',
        ]);

        $this->accountB = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        $this->actingAs($this->userA);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    protected function nonexistentId(): string
    {
        return (string) Str::uuid();
    }

    /** @test */
    public function showOnAnotherUsersConnectionReturns404()
    {
        $response = $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}"
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function showOnAnotherUsersConnectionIsIndistinguishableFromNonexistent()
    {
        $foreign = $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}"
        );

        $missing = $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->nonexistentId()}"
        );

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame($missing->getContent(), $foreign->getContent());
    }

    /** @test */
    public function syncOnAnotherUsersConnectionReturns404()
    {
        Queue::fake();

        $response = $this->postJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}/sync"
        );

        $response->assertStatus(404);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function syncOnAnotherUsersConnectionIsIndistinguishableFromNonexistent()
    {
        Queue::fake();

        $foreign = $this->postJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}/sync"
        );

        $missing = $this->postJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->nonexistentId()}/sync"
        );

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame($missing->getContent(), $foreign->getContent());
    }

    /** @test */
    public function crossUserRefusalIsNever403()
    {
        $show = $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}"
        );
        $sync = $this->postJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}/sync"
        );

        $this->assertNotSame(403, $show->getStatusCode());
        $this->assertNotSame(403, $sync->getStatusCode());
    }

    /** @test */
    public function ownerCanStillReachTheirOwnConnection()
    {
        $accountA = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userA->id,
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$accountA->id}"
        )->assertStatus(200);
    }

    /** @test */
    public function crossUserRefusalDoesNotLeakConnectionDetails()
    {
        $body = $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}"
        )->getContent();

        $this->assertStringNotContainsString('fake-step', $body);
        $this->assertStringNotContainsString($this->userB->id, $body);
        $this->assertStringNotContainsString($this->accountB->id, $body);
    }
}
