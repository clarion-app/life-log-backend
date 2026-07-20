<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\CarbonImmutable;

class ConnectionAttemptModelTest extends TestCase
{
    /** @test T009 — isUsable() returns true when not consumed and not expired */
    public function isUsableTrueWhenFresh(): void
    {
        $attempt = ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
            'state_hash' => hash('sha256', 'some-state-value'),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($attempt->isUsable());
    }

    /** @test T009 — isUsable() returns false when consumed */
    public function isUsableFalseWhenConsumed(): void
    {
        $attempt = ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
            'state_hash' => hash('sha256', 'some-state-value'),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
            'consumed_at' => now(),
        ]);

        $this->assertFalse($attempt->isUsable());
    }

    /** @test T009 — isUsable() returns false when expired */
    public function isUsableFalseWhenExpired(): void
    {
        $attempt = ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
            'state_hash' => hash('sha256', 'some-state-value'),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($attempt->isUsable());
    }

    /** @test T009 — isUsable() returns false when both consumed and expired */
    public function isUsableFalseWhenBothConsumedAndExpired(): void
    {
        $attempt = ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
            'state_hash' => hash('sha256', 'some-state-value'),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->subHour(),
            'consumed_at' => now(),
        ]);

        $this->assertFalse($attempt->isUsable());
    }

    /** @test T009 — state_hash unique index rejects a duplicate */
    public function stateHashUniqueIndexRejectsDuplicate(): void
    {
        $hash = hash('sha256', 'unique-state-value');

        ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
            'state_hash' => $hash,
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000002',
            'external_service' => 'test-service',
            'state_hash' => $hash,
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);
    }

    /** @test T009 — UUID primary key generation works */
    public function generatesUuidPrimaryKey(): void
    {
        $attempt = ConnectionAttempt::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
            'state_hash' => hash('sha256', 'state-value'),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertNotNull($attempt->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $attempt->id,
        );
    }
}
