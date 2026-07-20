<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\BackfillWindow;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Tests\Support\ScriptedSyncService;

/**
 * T084 — BackfillWindow unit tests.
 *
 * - until := backfilled_to
 * - since := until - maxWindow(type)
 * - NO clamp at connected_at
 * - null when the type is complete
 * - no floor — walk stops when provider stops serving data (FR-013)
 */
class BackfillWindowTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '88888888-8888-8888-8888-888888888888',
            'external_service' => 'scripted-sync',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(30),
        ]);
    }

    /** @test  null when type is already complete */
    public function returnsNullWhenComplete(): void
    {
        $account = $this->makeAccount();
        $now = CarbonImmutable::now();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now->subDays(100),
            'complete_at' => $now,
        ]);

        $service = ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');

        $result = BackfillWindow::next($state, $service);
        $this->assertNull($result, 'Complete type should return null window.');
    }

    /** @test  until := backfilled_to */
    public function untilEqualsBackfilledTo(): void
    {
        $account = $this->makeAccount();
        $now = CarbonImmutable::now()->startOfSecond();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now,
        ]);

        $service = ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');

        $result = BackfillWindow::next($state, $service);
        $this->assertNotNull($result);
        $this->assertEquals($now, $result->until, 'until should equal backfilled_to.');
    }

    /** @test  since := until - maxWindow(type) */
    public function sinceIsUntilMinusMaxWindow(): void
    {
        $account = $this->makeAccount();
        $now = CarbonImmutable::now()->startOfSecond();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now,
        ]);

        $service = ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');

        $result = BackfillWindow::next($state, $service);
        $this->assertNotNull($result);
        $expectedSince = $now->copy()->sub(new \DateInterval('P90D'));
        $this->assertEquals($expectedSince, $result->since, 'since should be until minus maxWindow.');
    }

    /** @test  no clamp at connected_at */
    public function noClampAtConnectedAt(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(30);
        $account = $this->makeAccount();
        $account->update(['connected_at' => $connectedAt]);

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $connectedAt->copy()->subDays(10),
        ]);

        $service = ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');

        $result = BackfillWindow::next($state, $service);
        $this->assertNotNull($result);
        // Since should be BEFORE connected_at (no clamp)
        $this->assertTrue(
            $result->since->lt($connectedAt),
            'BackfillWindow must not clamp at connected_at — it walks back past connection date.',
        );
    }

    /** @test  null maxWindow means full history */
    public function nullMaxWindowGivesFullHistory(): void
    {
        $account = $this->makeAccount();
        $now = CarbonImmutable::now();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now,
        ]);

        $service = ScriptedSyncService::emitting([]);
        // No maxWindow set → null

        $result = BackfillWindow::next($state, $service);
        $this->assertNotNull($result);
        // When maxWindow is null, since should be very far back (the beginning)
        $this->assertTrue(
            $result->since->lt($now->copy()->subYears(10)),
            'null maxWindow should produce a very wide since range.',
        );
    }

    /** @test  heart rate uses 14-day window */
    public function heartRateUses14DayWindow(): void
    {
        $account = $this->makeAccount();
        $now = CarbonImmutable::now()->startOfSecond();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::HeartRate->value,
            'backfilled_to' => $now,
        ]);

        $service = ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::HeartRate, 'P14D');

        $result = BackfillWindow::next($state, $service);
        $this->assertNotNull($result);
        $expectedSince = $now->copy()->sub(new \DateInterval('P14D'));
        $this->assertEquals($expectedSince, $result->since, 'Heart rate should use 14-day window.');
    }
}
