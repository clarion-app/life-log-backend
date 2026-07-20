<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\SyncAttempt;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\RecordingMultiChain;
use Tests\Support\ScriptedSyncService;

class SyncStateBridgeExclusionTest extends TestCase
{
    protected RecordingMultiChain $spy;

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = $this->enableRecordingBridge();

        // Seed data_stream_registries for ConnectedAccount so it resolves to a stream
        DB::table('data_stream_registries')->insertOrIgnore([
            [
                'class_name' => ConnectedAccount::class,
                'data_stream' => 'life_log_connected_accounts',
            ],
        ]);
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '88888888-8888-8888-8888-888888888888',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => $connectedAt,
        ]);
    }

    private function registerScriptedService(ScriptedSyncService $service): void
    {
        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $service,
        );
    }

    private function makeRunner(): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            app(HealthServiceRegistry::class),
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /* ------------------------------------------------------------------
     * T030: Bridge exclusion — AccountSyncState and SyncAttempt writes
     *        publish zero; only ConnectedAccount state changes publish
     * ------------------------------------------------------------------ */

    public function test_syncPublishesOnlyConnectedAccountStateChanges(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 2, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        // Clear any publishes from account creation
        $this->spy->published = [];

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // Collect all published streams
        $publishedStreams = array_map(fn ($p) => $p['stream'], $this->spy->published);

        // AccountSyncState and SyncAttempt writes should NOT publish
        // (they are not bridged — constitution §III)
        foreach ($this->spy->published as $publish) {
            $stream = $publish['stream'];

            // The stream should only be for ConnectedAccount changes
            // AccountSyncState and SyncAttempt should never appear
            $this->assertStringNotContainsString(
                'account_sync_states',
                $stream,
                'AccountSyncState writes should not publish to the bridge',
            );
            $this->assertStringNotContainsString(
                'sync_attempts',
                $stream,
                'SyncAttempt writes should not publish to the bridge',
            );
        }

        // A successful sync should NOT change sync_state (stays 'normal'),
        // so there should be zero publishes at all
        $this->assertCount(
            0,
            $this->spy->published,
            'A successful sync should produce zero bridge publishes (sync_state unchanged)',
        );
    }

    public function test_failureFlaggingPublishesConnectedAccountStateChange(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // Service that throws on the first fetch
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $service->throwServiceUnavailableOn(1);
        $this->registerScriptedService($service);

        // Set consecutive_failures to 4 so the next failure triggers needs_attention
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 4,
        ]);

        // Clear any publishes from setup
        $this->spy->published = [];

        // Temporarily set max consecutive failures to 5
        config(['life-log.sync_max_consecutive_failures' => 5]);

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Failure, $result->outcome);

        // The account should be flagged as needs_attention
        $account->refresh();
        $this->assertEquals('needs_attention', $account->sync_state);

        // The flagging (sync_state change on ConnectedAccount) SHOULD publish
        // because ConnectedAccount is bridged
        $connectedAccountPublishes = array_filter(
            $this->spy->published,
            fn ($p) => str_contains($p['stream'], 'connected_accounts'),
        );

        $this->assertGreaterThan(
            0,
            count($connectedAccountPublishes),
            'Flagging an account as needs_attention should publish to the bridge',
        );

        // AccountSyncState and SyncAttempt should still not publish
        foreach ($this->spy->published as $publish) {
            $this->assertStringNotContainsString(
                'account_sync_states',
                $publish['stream'],
                'AccountSyncState writes should not publish',
            );
            $this->assertStringNotContainsString(
                'sync_attempts',
                $publish['stream'],
                'SyncAttempt writes should not publish',
            );
        }
    }
}
