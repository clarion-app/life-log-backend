<?php

namespace Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * On-demand sync (FR-020): a user asks for an update now and gets one,
 * without stacking a second run on top of one already in flight, and
 * without starting a run that is known in advance to fail (FR-020a).
 */
class OnDemandSyncTest extends TestCase
{
    protected User $user;
    protected ConnectedAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'hashed',
        ]);

        $this->account = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->user);
    }

    protected function syncUrl(?string $id = null): string
    {
        return '/api/clarion-app/life-log/connected-accounts/'
            . ($id ?? $this->account->id) . '/sync';
    }

    /** @test */
    public function aHealthyConnectionIsQueued()
    {
        Queue::fake();

        $response = $this->postJson($this->syncUrl());

        $response->assertStatus(202);
        $response->assertJson(['status' => 'queued']);

        Queue::assertPushed(SyncConnectedAccountJob::class);
    }

    /** @test */
    public function aSecondRequestWhileOneIsInFlightReportsAlreadyRunning()
    {
        Queue::fake();

        // Simulate a run holding the per-account lock.
        $lock = Cache::lock('life-log:sync:' . $this->account->id, 900);
        $this->assertTrue($lock->get());

        try {
            $response = $this->postJson($this->syncUrl());

            $response->assertStatus(202);
            $response->assertJson(['status' => 'already_running']);
        } finally {
            $lock->release();
        }

        Queue::assertNothingPushed();
    }

    /** @test */
    public function anAlreadyRunningRefusalDoesNotDispatchAnOverlappingJob()
    {
        Queue::fake();

        $lock = Cache::lock('life-log:sync:' . $this->account->id, 900);
        $lock->get();

        try {
            $this->postJson($this->syncUrl());
            $this->postJson($this->syncUrl());
        } finally {
            $lock->release();
        }

        Queue::assertNothingPushed();

        // Once the run finishes, a fresh request queues normally again.
        $this->postJson($this->syncUrl())->assertJson(['status' => 'queued']);
        Queue::assertPushed(SyncConnectedAccountJob::class, 1);
    }

    /** @test */
    public function aNeedsAttentionConnectionIsRefusedWithItsReason()
    {
        Queue::fake();

        $this->account->sync_state = 'needs_attention';
        $this->account->save();

        AccountSyncState::create([
            'connected_account_id' => $this->account->id,
            'consecutive_failures' => 3,
            'needs_attention_reason' => 'credential_rotated',
        ]);

        $response = $this->postJson($this->syncUrl());

        $response->assertStatus(409);
        $response->assertExactJson([
            'error' => 'needs_attention',
            'reason' => 'credential_rotated',
        ]);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function theRefusalReasonFallsBackToTheLastFailureKind()
    {
        Queue::fake();

        $this->account->sync_state = 'needs_attention';
        $this->account->save();

        AccountSyncState::create([
            'connected_account_id' => $this->account->id,
            'consecutive_failures' => 5,
            'last_failure_kind' => 'credentials_rejected',
        ]);

        $response = $this->postJson($this->syncUrl());

        $response->assertStatus(409);
        $response->assertJson(['reason' => 'credentials_rejected']);
    }

    /** @test */
    public function theNeedsAttentionRefusalOutranksTheLockCheck()
    {
        Queue::fake();

        $this->account->sync_state = 'needs_attention';
        $this->account->save();

        // Even with the lock free, a doomed connection is refused rather
        // than attempted.
        $response = $this->postJson($this->syncUrl());

        $response->assertStatus(409);
        Queue::assertNothingPushed();

        // And the lock was not consumed by the refused request.
        $lock = Cache::lock('life-log:sync:' . $this->account->id, 900);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    /** @test */
    public function recoveringFromNeedsAttentionRestoresOnDemandSync()
    {
        Queue::fake();

        $this->account->sync_state = 'needs_attention';
        $this->account->save();

        $this->postJson($this->syncUrl())->assertStatus(409);

        $this->account->resetSyncHealth();

        $this->postJson($this->syncUrl())->assertStatus(202)
            ->assertJson(['status' => 'queued']);
    }
}
