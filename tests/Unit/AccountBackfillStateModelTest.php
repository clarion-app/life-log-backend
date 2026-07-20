<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * T029 — AccountBackfillState model tests.
 *
 * - Casts on the five timestamps
 * - unique(connected_account_id, type) rejects a duplicate
 * - isComplete() reflects complete_at
 */
class AccountBackfillStateModelTest extends TestCase
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
            'connected_at' => CarbonImmutable::now(),
        ]);
    }

    /** @test  casts on the five timestamps */
    public function timestampCastsAreCorrect(): void
    {
        $account = $this->makeAccount();

        $now = CarbonImmutable::now();
        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now,
            'cursor_since' => $now,
            'cursor_until' => $now->addDay(),
            'complete_at' => $now->addWeek(),
            'completeness_determined_at' => $now,
        ]);

        $fresh = AccountBackfillState::find($state->id);

        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->backfilled_to);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->cursor_since);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->cursor_until);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->complete_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->completeness_determined_at);
    }

    /** @test  unique(connected_account_id, type) rejects a duplicate */
    public function uniqueConstraintRejectsDuplicate(): void
    {
        $account = $this->makeAccount();

        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        // The unique constraint should reject a duplicate
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);
    }

    /** @test  isComplete() returns true when complete_at is set */
    public function isCompleteReflectsCompleteAt(): void
    {
        $account = $this->makeAccount();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
            'complete_at' => CarbonImmutable::now(),
        ]);

        $this->assertTrue($state->isComplete(), 'State with complete_at set should be complete.');
    }

    /** @test  isComplete() returns false when complete_at is null */
    public function isCompleteFalseWhenNotComplete(): void
    {
        $account = $this->makeAccount();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
            'complete_at' => null,
        ]);

        $this->assertFalse($state->isComplete(), 'State without complete_at should not be complete.');
    }

    /** @test  backfillState relation on ConnectedAccount */
    public function connectedAccountHasBackfillStateRelation(): void
    {
        $account = $this->makeAccount();

        $state = AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        $resolved = $account->backfillState(MeasurementType::Steps);
        $this->assertInstanceOf(AccountBackfillState::class, $resolved);
        $this->assertSame($state->id, $resolved->id);
    }

    /** @test  backfillState returns null for missing type */
    public function backfillStateReturnsNullForMissingType(): void
    {
        $account = $this->makeAccount();

        $resolved = $account->backfillState(MeasurementType::Steps);
        $this->assertNull($resolved, 'backfillState should return null for a type with no state.');
    }

    /** @test  HasMany relation returns all states for an account */
    public function backfillStatesReturnsAllStates(): void
    {
        $account = $this->makeAccount();

        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Weight->value,
            'backfilled_to' => CarbonImmutable::now(),
        ]);

        $states = $account->backfillStates;
        $this->assertCount(2, $states);
    }
}
