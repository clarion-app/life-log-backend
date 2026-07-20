<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptFactory;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionAttemptFactoryTest extends TestCase
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
    public function storesSha256HashOfStateNeverPlaintext()
    {
        $factory = app(ConnectionAttemptFactory::class);
        $state = base64_encode(random_bytes(32));
        $redirectUri = 'https://example.com/callback';

        $attempt = $factory->create(
            userId: auth()->id(),
            externalService: 'fake-step',
            state: $state,
            redirectUri: $redirectUri,
        );

        $expectedHash = hash('sha256', $state);
        $this->assertEquals($expectedHash, $attempt->state_hash);

        // Plaintext state never appears in the database
        $rawRow = DB::table('life_log_connection_attempts')->where('id', $attempt->id)->first();
        $this->assertStringNotContainsString($state, $rawRow->state_hash);
        $this->assertStringNotContainsString($state, (string) $rawRow->redirect_uri);
    }

    /** @test */
    public function rejectsStateUnder32BytesEntropy()
    {
        $factory = app(ConnectionAttemptFactory::class);

        // Short state — less than 32 bytes
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('state must be at least 32 bytes of entropy');
        $factory->create(
            userId: auth()->id(),
            externalService: 'fake-step',
            state: 'weak',
            redirectUri: 'https://example.com/callback',
        );
    }

    /** @test */
    public function setsExpiresAtToOneHourFromNow()
    {
        $factory = app(ConnectionAttemptFactory::class);
        $state = base64_encode(random_bytes(32));

        $before = now();
        $attempt = $factory->create(
            userId: auth()->id(),
            externalService: 'fake-step',
            state: $state,
            redirectUri: 'https://example.com/callback',
        );
        $after = now();

        $this->assertNotNull($attempt->expires_at);
        $this->assertTrue($attempt->expires_at->gte($before->copy()->addHour()->subMinute()));
        $this->assertTrue($attempt->expires_at->lte($after->copy()->addHour()->addMinute()));
    }

    /** @test */
    public function bindsUserIdExternalServiceRedirectUri()
    {
        $factory = app(ConnectionAttemptFactory::class);
        $state = base64_encode(random_bytes(32));
        $redirectUri = 'https://example.com/callback';

        $attempt = $factory->create(
            userId: auth()->id(),
            externalService: 'fake-step',
            state: $state,
            redirectUri: $redirectUri,
        );

        $this->assertEquals(auth()->id(), $attempt->user_id);
        $this->assertEquals('fake-step', $attempt->external_service);
        $this->assertEquals($redirectUri, $attempt->redirect_uri);
        $this->assertNull($attempt->consumed_at);
    }

    /** @test */
    public function returnsConnectionAttemptModel()
    {
        $factory = app(ConnectionAttemptFactory::class);
        $state = base64_encode(random_bytes(32));

        $result = $factory->create(
            userId: auth()->id(),
            externalService: 'fake-step',
            state: $state,
            redirectUri: 'https://example.com/callback',
        );

        $this->assertInstanceOf(ConnectionAttempt::class, $result);
        $this->assertNotNull($result->id);
    }
}
