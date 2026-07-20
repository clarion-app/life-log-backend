<?php

namespace Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A user listing their connections sees, for each one, what service it is,
 * whether it is working, and when it last succeeded (FR-019). The list is
 * owner-scoped: another user's connections are not in it (FR-024).
 */
class ConnectionListingTest extends TestCase
{
    protected User $userA;
    protected User $userB;

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

        $this->actingAs($this->userA);
    }

    protected function makeAccount(User $user, string $service, string $syncState = 'normal'): ConnectedAccount
    {
        return ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'external_service' => $service,
            'sync_state' => $syncState,
            'connected_at' => now()->subDays(3),
        ]);
    }

    protected function listing(): array
    {
        $response = $this->getJson('/api/clarion-app/life-log/connected-accounts');
        $response->assertStatus(200);

        return $response->json('connections');
    }

    /** @test */
    public function listsOnlyTheCallersOwnConnections()
    {
        $mine = $this->makeAccount($this->userA, 'fake-step');
        $theirs = $this->makeAccount($this->userB, 'fake-span');

        $connections = $this->listing();

        $this->assertCount(1, $connections);
        $this->assertSame($mine->id, $connections[0]['id']);

        $body = $this->getJson('/api/clarion-app/life-log/connected-accounts')->getContent();
        $this->assertStringNotContainsString($theirs->id, $body);
        $this->assertStringNotContainsString('fake-span', $body);
    }

    /** @test */
    public function eachEntryCarriesTheDocumentedKeys()
    {
        $this->makeAccount($this->userA, 'fake-step');

        $entry = $this->listing()[0];

        $this->assertSame([
            'id',
            'external_service',
            'status',
            'last_successful_sync_at',
            'connected_at',
            'needs_attention_reason',
        ], array_keys($entry));
    }

    /** @test */
    public function aNeverSyncedConnectionReportsNullRatherThanAFabricatedTimestamp()
    {
        $account = $this->makeAccount($this->userA, 'fake-step');

        // No sync state row at all — the connection has never run.
        $this->assertNull($account->syncState);

        $entry = $this->listing()[0];

        $this->assertNull($entry['last_successful_sync_at']);
        $this->assertSame('healthy', $entry['status']);
        $this->assertNull($entry['needs_attention_reason']);
    }

    /** @test */
    public function aStateRowWithNoSuccessYetStillReportsNull()
    {
        $account = $this->makeAccount($this->userA, 'fake-step');

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
            'last_success_at' => null,
        ]);

        $this->assertNull($this->listing()[0]['last_successful_sync_at']);
    }

    /** @test */
    public function aSuccessfulSyncTimeIsReported()
    {
        $account = $this->makeAccount($this->userA, 'fake-step');
        $succeededAt = now()->subHours(2)->startOfSecond();

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
            'last_success_at' => $succeededAt,
        ]);

        $entry = $this->listing()[0];

        $this->assertNotNull($entry['last_successful_sync_at']);
        $this->assertSame(
            $succeededAt->toJSON(),
            $entry['last_successful_sync_at']
        );
    }

    /** @test */
    public function connectedAtIsReported()
    {
        $account = $this->makeAccount($this->userA, 'fake-step');

        $this->assertSame(
            $account->connected_at->toJSON(),
            $this->listing()[0]['connected_at']
        );
    }

    /** @test */
    public function needsAttentionIsVisiblyDistinctFromHealthy()
    {
        $healthy = $this->makeAccount($this->userA, 'fake-step');
        $ailing = $this->makeAccount($this->userA, 'fake-span', 'needs_attention');

        $byId = collect($this->listing())->keyBy('id');

        $this->assertSame('healthy', $byId[$healthy->id]['status']);
        $this->assertSame('needs_attention', $byId[$ailing->id]['status']);
    }

    /** @test */
    public function theManagementSideReasonIsReportedWhenPresent()
    {
        $account = $this->makeAccount($this->userA, 'fake-step', 'needs_attention');

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
            'needs_attention_reason' => 'credential_rotated',
            'last_failure_kind' => 'credentials_rejected',
        ]);

        $this->assertSame('credential_rotated', $this->listing()[0]['needs_attention_reason']);
    }

    /** @test */
    public function theReasonFallsBackToLastFailureKind()
    {
        $account = $this->makeAccount($this->userA, 'fake-step', 'needs_attention');

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 5,
            'needs_attention_reason' => null,
            'last_failure_kind' => 'service_unavailable',
        ]);

        $this->assertSame('service_unavailable', $this->listing()[0]['needs_attention_reason']);
    }

    /** @test */
    public function theReasonIsNullWhileHealthy()
    {
        $account = $this->makeAccount($this->userA, 'fake-step');

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
            'last_failure_kind' => 'service_unavailable',
            'last_success_at' => now(),
        ]);

        $entry = $this->listing()[0];

        $this->assertSame('healthy', $entry['status']);
        $this->assertNull($entry['needs_attention_reason']);
    }

    /** @test */
    public function anEmptyListIsAnEmptyCollectionNotAnError()
    {
        $response = $this->getJson('/api/clarion-app/life-log/connected-accounts');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('connections'));
    }

    /** @test */
    public function aDisconnectedConnectionIsNotListed()
    {
        $account = $this->makeAccount($this->userA, 'fake-step');
        $account->delete();

        $this->assertSame([], $this->listing());
    }
}
