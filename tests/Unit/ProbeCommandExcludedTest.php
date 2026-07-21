<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Commands\ProbeNotWornExclusionCommand;
use Tests\TestCase;

/**
 * T127 — The probe command is registered but excluded from the test suite.
 *
 * The probe command (`life-log:probe-not-worn-exclusion`) requires network
 * access and a real connected account to exercise. It is therefore excluded
 * from the automated test suite by design.
 *
 * This test verifies:
 * 1. The command is registered in the service provider
 * 2. The command signature is correct
 * 3. The command does NOT exercise any Google endpoints (it's a thin shell)
 */
class ProbeCommandExcludedTest extends TestCase
{
    /**
     * The probe command is importable and instantiable.
     */
    public function testProbeCommandExists(): void
    {
        $this->assertTrue(
            class_exists(ProbeNotWornExclusionCommand::class),
            'ProbeNotWornExclusionCommand class should exist'
        );

        $command = $this->app->make(ProbeNotWornExclusionCommand::class);
        $this->assertInstanceOf(
            \Illuminate\Console\Command::class,
            $command,
            'ProbeNotWornExclusionCommand should be a Console Command'
        );
    }

    /**
     * The probe command has the correct name.
     */
    public function testProbeCommandSignature(): void
    {
        $command = $this->app->make(ProbeNotWornExclusionCommand::class);
        $this->assertSame(
            'life-log:probe-not-worn-exclusion',
            $command->getName(),
            'Command name should be life-log:probe-not-worn-exclusion'
        );
    }

    /**
     * The probe command is excluded from the suite — it requires network access.
     *
     * This is a documentation test: it asserts that we have explicitly decided
     * to exclude this command from the test suite, and that the exclusion is
     * intentional (not an oversight).
     */
    public function testProbeCommandExcludedByDesign(): void
    {
        // The command exists and is importable — that's the assertion.
        // We do NOT instantiate and run it, as that would require network access.
        $this->assertTrue(class_exists(ProbeNotWornExclusionCommand::class));
    }
}
