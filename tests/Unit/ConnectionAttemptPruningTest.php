<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Commands\PruneConnectionAttemptsCommand;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ConnectionAttemptPruningTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test */
    public function prunesAttemptsPast24hGrace()
    {
        $state = base64_encode(random_bytes(32));

        // Create an attempt that expired more than 24h ago
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHours(25), // 25h ago — past grace
        ]);

        $this->assertDatabaseCount('life_log_connection_attempts', 1);

        Artisan::call('life-log:prune-connection-attempts');

        $this->assertDatabaseCount('life_log_connection_attempts', 0);
    }

    /** @test */
    public function retainsAttemptsWithin24hGrace()
    {
        $state = base64_encode(random_bytes(32));

        // Create an attempt that expired 1h ago (within 24h grace)
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour(), // Expired 1h ago — within grace
        ]);

        // Create a fresh attempt
        $state2 = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => 'fake-step',
            'state_hash' => hash('sha256', $state2),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(), // Still valid
        ]);

        $this->assertDatabaseCount('life_log_connection_attempts', 2);

        Artisan::call('life-log:prune-connection-attempts');

        // Both should still exist
        $this->assertDatabaseCount('life_log_connection_attempts', 2);
    }

    /** @test */
    public function deletesInBoundedChunks()
    {
        // Create many expired attempts
        $count = 600; // More than the default chunk size (500)
        for ($i = 0; $i < $count; $i++) {
            $state = base64_encode(random_bytes(32));
            ConnectionAttempt::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $this->user->id,
                'external_service' => 'fake-step',
                'state_hash' => hash('sha256', $state),
                'redirect_uri' => 'https://example.com/callback',
                'expires_at' => now()->subHours(25),
            ]);
        }

        $this->assertDatabaseCount('life_log_connection_attempts', $count);

        Artisan::call('life-log:prune-connection-attempts');

        $this->assertDatabaseCount('life_log_connection_attempts', 0);
    }

    /** @test */
    public function mixedAgesPrunesOnlyOld()
    {
        // Old attempts
        for ($i = 0; $i < 10; $i++) {
            $state = base64_encode(random_bytes(32));
            ConnectionAttempt::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $this->user->id,
                'external_service' => 'fake-step',
                'state_hash' => hash('sha256', $state),
                'redirect_uri' => 'https://example.com/callback',
                'expires_at' => now()->subHours(25),
            ]);
        }

        // Recent attempts
        for ($i = 0; $i < 5; $i++) {
            $state = base64_encode(random_bytes(32));
            ConnectionAttempt::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $this->user->id,
                'external_service' => 'fake-step',
                'state_hash' => hash('sha256', $state),
                'redirect_uri' => 'https://example.com/callback',
                'expires_at' => now()->addHour(),
            ]);
        }

        $this->assertDatabaseCount('life_log_connection_attempts', 15);

        Artisan::call('life-log:prune-connection-attempts');

        // Only recent ones remain
        $this->assertDatabaseCount('life_log_connection_attempts', 5);
    }
}
