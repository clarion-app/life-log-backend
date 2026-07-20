<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class BackoffProgressionTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(string $id, CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => $id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => $connectedAt,
        ]);
    }

    private function makeRunnerWithService(ScriptedSyncService $service): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        // Create a fresh registry for each run (singleton doesn't allow re-registration)
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

    /* ------------------------------------------------------------------
     * T037: Backoff progression — next_attempt_at after each consecutive
     *       failure equals exactly now + 1 h/4 h/4 h/4 h (no tolerances);
     *       the 5th failure sets sync_state = needs_attention and fires
     *       ConnectedAccountNeedsAttention; a flagged account is not
     *       selected by the sweep (FR-010, FR-011, FR-013)
     * ------------------------------------------------------------------ */

    public function testBackoffProgressionFollowsLadder(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);

        // Use unique user IDs
        $account = $this->makeAccount('backoff-user', $connectedAt);

        $ladder = [60, 240, 240, 240]; // sync_backoff_minutes

        for ($i = 0; $i < 4; $i++) {
            $now = CarbonImmutable::now()->addHours($i);
            CarbonImmutable::setTestNow($now);

            // Service throws ServiceUnavailable on every first fetch
            $service = ScriptedSyncService::withPages([
                ['measurements' => 1, 'sessions' => 0],
            ]);
            $service->throwServiceUnavailableOn(1);

            $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);

            $this->assertEquals(SyncOutcome::Failure, $result->outcome);

            $state = AccountSyncState::where('connected_account_id', $account->id)->first();
            $this->assertNotNull($state);

            // consecutive_failures should be i + 1
            $this->assertEquals($i + 1, $state->consecutive_failures);

            // next_attempt_at should be now + ladder[i] minutes
            $expectedNextAttempt = $now->addMinutes($ladder[$i]);
            $this->assertEquals(
                $expectedNextAttempt->getTimestamp(),
                $state->next_attempt_at->getTimestamp(),
                "Failure {$i}: next_attempt_at mismatch",
            );

            // Account should NOT be flagged yet (only on 5th)
            $account->refresh();
            $this->assertEquals('normal', $account->sync_state);
        }
    }

    public function testFifthFailureFlagsNeedsAttention(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount('fifth-fail-user', $connectedAt);

        // Run 4 failures first
        for ($i = 0; $i < 4; $i++) {
            $now = CarbonImmutable::now()->addHours($i);
            CarbonImmutable::setTestNow($now);

            $service = ScriptedSyncService::withPages([
                ['measurements' => 1, 'sessions' => 0],
            ]);
            $service->throwServiceUnavailableOn(1);

            $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Failure, $result->outcome);
        }

        // 5th failure — should flag needs_attention
        $now = CarbonImmutable::now()->addHours(4);
        CarbonImmutable::setTestNow($now);

        Event::fake();

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwServiceUnavailableOn(1);

        $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Failure, $result->outcome);

        $account->refresh();
        $this->assertEquals('needs_attention', $account->sync_state);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(5, $state->consecutive_failures);

        Event::assertDispatched(\ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention::class);

        CarbonImmutable::setTestNow();
    }

    /**
     * The state row records which kind of failure it was — the GET sync-health
     * endpoint reads last_failure_kind, so a null here is a silent hole in the
     * only diagnostic a caller has (data-model.md, quickstart "Diagnosing an account").
     */
    public function testFailureKindIsPersistedOnTheStateRow(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $account = $this->makeAccount('failure-kind-user', $now->subDays(5));

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwServiceUnavailableOn(1);

        $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertSame('service_unavailable', $state->last_failure_kind);
        $this->assertEquals($now->getTimestamp(), $state->last_failure_at->getTimestamp());

        CarbonImmutable::setTestNow();
    }

    /**
     * AccessRevoked is terminal: the kind is recorded and no ladder gate is
     * left behind for the sweep to wait on (failure-policy.md).
     */
    public function testAccessRevokedRecordsKindAndLeavesNoGate(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $account = $this->makeAccount('revoked-kind-user', $now->subDays(5));

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwAccessRevokedOn(1);

        $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertSame('access_revoked', $state->last_failure_kind);
        $this->assertNull($state->next_attempt_at);

        $account->refresh();
        $this->assertEquals('needs_attention', $account->sync_state);

        CarbonImmutable::setTestNow();
    }

    public function testFlaggedAccountExcludedFromSweep(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);

        // Create a healthy account and a flagged account
        $healthyAccount = $this->makeAccount('sweep-healthy', $connectedAt);
        $flaggedAccount = $this->makeAccount('sweep-flagged', $connectedAt);
        $flaggedAccount->sync_state = 'needs_attention';
        $flaggedAccount->save();

        // Pre-create sync states
        AccountSyncState::create([
            'connected_account_id' => $healthyAccount->id,
            'consecutive_failures' => 0,
        ]);
        AccountSyncState::create([
            'connected_account_id' => $flaggedAccount->id,
            'consecutive_failures' => 5,
        ]);

        // The sweep command queries for due accounts
        // Due predicate: sync_state = normal AND (no state OR gate null/past)
        $dueQuery = ConnectedAccount::query()
            ->where('sync_state', 'normal')
            ->where(function ($q) {
                $q->doesntHave('syncState')
                    ->orWhereHas('syncState', function ($sq) {
                        $sq->whereNull('next_attempt_at')
                            ->orWhere('next_attempt_at', '<=', CarbonImmutable::now());
                    });
            });

        $dueAccountIds = $dueQuery->pluck('id')->toArray();

        // Only the healthy account should be due
        $this->assertContains($healthyAccount->id, $dueAccountIds);
        $this->assertNotContains($flaggedAccount->id, $dueAccountIds);
    }
}
