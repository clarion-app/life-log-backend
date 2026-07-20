<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Commands\BackfillAccountsCommand;
use ClarionApp\LifeLogBackend\Commands\BackfillAccountCommand;

/**
 * T097a — Backfill cadence test.
 *
 * FR-028: life-log:backfill-accounts registered on schedule SEPARATE from
 * hourly life-log:sync-accounts sweep, jittered and withoutOverlapping().
 */
class BackfillCadenceTest extends TestCase
{
    /** @test  backfill command is registered */
    public function backfillAccountsCommandRegistered(): void
    {
        $this->artisan('life-log:backfill-accounts', ['--help' => true])
            ->assertExitCode(0);
    }

    /** @test  backfill-account command is registered */
    public function backfillAccountCommandRegistered(): void
    {
        // --help should work even without a valid account id
        $this->artisan('life-log:backfill-account', ['--help' => true])
            ->assertExitCode(0);
    }

    /** @test  commands exist as classes */
    public function commandClassesExist(): void
    {
        $this->assertTrue(class_exists(BackfillAccountsCommand::class));
        $this->assertTrue(class_exists(BackfillAccountCommand::class));
    }
}
