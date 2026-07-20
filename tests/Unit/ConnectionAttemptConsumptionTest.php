<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionAttemptConsumptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(
            \ClarionApp\Backend\Models\User::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'hashed',
            ]),
        );
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test */
    public function atomicUpdateWhereConsumedAtIsNull()
    {
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => auth()->id(),
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // Atomic consumption
        $rows = ConnectionAttempt::query()
            ->where('id', $attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $this->assertEquals(1, $rows);

        $attempt->refresh();
        $this->assertNotNull($attempt->consumed_at);
    }

    /** @test */
    public function twoConcurrentClaimsYieldOneWinner()
    {
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => auth()->id(),
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // First claim (simulating concurrent access via sequential updates)
        $first = ConnectionAttempt::query()
            ->where('id', $attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        // Second claim (the other concurrent request)
        $second = ConnectionAttempt::query()
            ->where('id', $attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $this->assertEquals(1, $first, 'First claim should succeed');
        $this->assertEquals(0, $second, 'Second claim should get zero rows');

        $attempt->refresh();
        $this->assertNotNull($attempt->consumed_at);
    }

    /** @test */
    public function loserGetsZeroAffectedRows()
    {
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => auth()->id(),
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
            'consumed_at' => now(), // Already consumed
        ]);

        $rows = ConnectionAttempt::query()
            ->where('id', $attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $this->assertEquals(0, $rows);
    }

    /** @test */
    public function consumedAttemptIsNotUsable()
    {
        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => auth()->id(),
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
            'consumed_at' => now(),
        ]);

        $this->assertFalse($attempt->isUsable());
    }
}
