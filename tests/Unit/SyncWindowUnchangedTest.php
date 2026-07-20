<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncWindow;
use Carbon\CarbonImmutable;

/**
 * T085 — SyncWindow still clamps at connected_at in every branch.
 *
 * This is why BackfillWindow is a sibling class rather than a flag.
 */
class SyncWindowUnchangedTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '88888888-8888-8888-8888-888888888888',
            'external_service' => 'test-service',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(30),
        ]);
    }

    /** @test  first sync clamps at connected_at */
    public function firstSyncClampsAtConnectedAt(): void
    {
        $account = $this->makeAccount();
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
        ]);

        $window = SyncWindow::for($account, $state, CarbonImmutable::now());
        $this->assertGreaterThanOrEqual(
            $account->connected_at,
            $window->since,
            'SyncWindow must clamp at connected_at on first sync.',
        );
    }

    /** @test  incremental sync clamps at connected_at */
    public function incrementalSyncClampsAtConnectedAt(): void
    {
        $account = $this->makeAccount();
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'synced_through_at' => CarbonImmutable::now()->subDays(1),
        ]);

        $window = SyncWindow::for($account, $state, CarbonImmutable::now());
        $this->assertGreaterThanOrEqual(
            $account->connected_at,
            $window->since,
            'SyncWindow must clamp at connected_at on incremental sync.',
        );
    }

    /** @test  cursor-based sync respects connected_at floor */
    public function cursorSyncRespectsConnectedAt(): void
    {
        $account = $this->makeAccount();
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'cursor' => 'some-cursor',
            'cursor_since' => $account->connected_at,
            'cursor_until' => CarbonImmutable::now(),
        ]);

        $window = SyncWindow::for($account, $state, CarbonImmutable::now());
        $this->assertGreaterThanOrEqual(
            $account->connected_at,
            $window->since,
            'SyncWindow cursor branch must respect connected_at.',
        );
    }
}
