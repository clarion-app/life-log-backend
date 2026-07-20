<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingMultiChain;

/**
 * T030 — AccountBackfillState publishes nothing to the bridge.
 *
 * Constitution §III: sync bookkeeping is not user data.
 * AccountBackfillState is a bookkeeping model and must not be bridged.
 */
class BackfillStateBridgeExclusionTest extends TestCase
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

        // Seed data_stream_registries for ConnectedAccount
        DB::table('data_stream_registries')->insertOrIgnore([
            [
                'class_name' => ConnectedAccount::class,
                'data_stream' => 'life_log_connected_accounts',
            ],
        ]);
    }

    /** @test  AccountBackfillState does not use EloquentMultiChainBridge */
    public function accountBackfillStateDoesNotUseBridge(): void
    {
        $traits = class_uses(AccountBackfillState::class);

        $this->assertNotContains(
            \ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge::class,
            $traits ?? [],
            'AccountBackfillState must not use EloquentMultiChainBridge trait.',
        );
    }

    /** @test  creating AccountBackfillState publishes nothing */
    public function createPublishesNothing(): void
    {
        $account = ConnectedAccount::create([
            'user_id' => '88888888-8888-8888-8888-888888888888',
            'external_service' => 'test-service',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now(),
        ]);

        // Clear any publishes from account creation
        $this->spy->published = [];

        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        $this->assertEmpty(
            $this->spy->published,
            'Creating AccountBackfillState should not publish to the bridge.',
        );
    }

    /** @test  updating AccountBackfillState publishes nothing */
    public function updatePublishesNothing(): void
    {
        $account = ConnectedAccount::create([
            'user_id' => '88888888-8888-8888-8888-888888888888',
            'external_service' => 'test-service',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now(),
        ]);

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        // Clear any publishes from creation
        $this->spy->published = [];

        $state->backfilled_to = CarbonImmutable::now()->addDay();
        $state->save();

        $this->assertEmpty(
            $this->spy->published,
            'Updating AccountBackfillState should not publish to the bridge.',
        );
    }

    /** @test  deleting AccountBackfillState publishes nothing */
    public function deletePublishesNothing(): void
    {
        $account = ConnectedAccount::create([
            'user_id' => '88888888-8888-8888-8888-888888888888',
            'external_service' => 'test-service',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now(),
        ]);

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        // Clear any publishes from creation
        $this->spy->published = [];

        $state->delete();

        $this->assertEmpty(
            $this->spy->published,
            'Deleting AccountBackfillState should not publish to the bridge.',
        );
    }
}
