<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SweepJitterTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(string $id, string $syncState = 'normal'): ConnectedAccount
    {
        // `id` is not fillable, so it cannot be passed through create() — the
        // boot listener would assign a random uuid instead and the jitter this
        // test calls deterministic would be anything but.
        $account = new ConnectedAccount([
            'user_id' => $id, // Use id as user_id to satisfy unique constraint
            'external_service' => 'test-service',
            'sync_state' => $syncState,
            'connected_at' => CarbonImmutable::now()->subDays(10),
        ]);

        $account->id = $id;
        $account->save();

        return $account;
    }

    /* ------------------------------------------------------------------
     * T029: Sweep dispatch jitter
     * ------------------------------------------------------------------ */

    public function test_sweepDispatchJitterIsDeterministic(): void
    {
        Queue::fake();

        $jitterSeconds = (int) config('life-log.sync_jitter_seconds', 900);

        // Create multiple accounts with known IDs
        $accounts = [];
        for ($i = 0; $i < 5; $i++) {
            $id = sprintf('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa%02d', $i);
            $accounts[] = $this->makeAccount($id);
        }

        // Run the sweep command
        $this->artisan('life-log:sync-accounts')->run();

        // Each account should have a job dispatched with a delay
        $delays = [];
        foreach ($accounts as $account) {
            $expectedDelay = abs(crc32($account->id)) % $jitterSeconds;

            Queue::assertPushed(
                \ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob::class,
                function ($job) use ($account, $expectedDelay) {
                    // Check the job is for the right account
                    if ($job->connectedAccountId !== $account->id) {
                        return false;
                    }

                    // Check the delay matches
                    return $job->delay === $expectedDelay;
                },
            );

            $delays[$account->id] = $expectedDelay;
        }

        // Delays should be deterministic (same account → same delay)
        $this->assertCount(5, $delays);

        // All delays should be strictly under the jitter window
        foreach ($delays as $delay) {
            $this->assertLessThan($jitterSeconds, $delay, "Delay {$delay} should be < {$jitterSeconds}");
            $this->assertGreaterThanOrEqual(0, $delay, "Delay {$delay} should be >= 0");
        }

        // Delays should be distinct across different accounts (with high probability)
        $uniqueDelays = array_unique($delays);
        $this->assertCount(5, $uniqueDelays, 'Delays should be distinct across accounts');
    }

    public function test_sweepHonorsDuePredicate(): void
    {
        Queue::fake();

        // Account 1: normal, no state row → due
        $dueAccount = $this->makeAccount('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb01');

        // Account 2: normal, state row with next_attempt_at in future → NOT due
        $gatedAccount = $this->makeAccount('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb02');
        AccountSyncState::create([
            'connected_account_id' => $gatedAccount->id,
            'next_attempt_at' => CarbonImmutable::now()->addHours(2),
        ]);

        // Account 3: needs_attention → NOT due
        $attentionAccount = $this->makeAccount('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb03', 'needs_attention');

        // Account 4: normal, state row with next_attempt_at null → due
        $dueAccount2 = $this->makeAccount('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb04');
        AccountSyncState::create([
            'connected_account_id' => $dueAccount2->id,
            'consecutive_failures' => 0,
        ]);

        // Account 5: normal, state row with next_attempt_at in past → due
        $dueAccount3 = $this->makeAccount('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb05');
        AccountSyncState::create([
            'connected_account_id' => $dueAccount3->id,
            'next_attempt_at' => CarbonImmutable::now()->subHours(1),
        ]);

        $this->artisan('life-log:sync-accounts')->run();

        // Due accounts should have jobs dispatched
        Queue::assertPushed(
            \ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob::class,
            function ($job) use ($dueAccount, $dueAccount2, $dueAccount3) {
                return in_array($job->connectedAccountId, [
                    $dueAccount->id,
                    $dueAccount2->id,
                    $dueAccount3->id,
                ]);
            },
        );

        // Gated and needs_attention accounts should NOT have jobs
        Queue::assertNotPushed(
            \ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob::class,
            function ($job) use ($gatedAccount, $attentionAccount) {
                return in_array($job->connectedAccountId, [
                    $gatedAccount->id,
                    $attentionAccount->id,
                ]);
            },
        );
    }
}
