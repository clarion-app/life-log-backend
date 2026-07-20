<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Illuminate\Support\Str;

class ServiceCredentialModelTest extends TestCase
{
    /** @test T008 — client_secret round-trips through the encrypted cast */
    public function clientSecretRoundTripsThroughEncryptedCast(): void
    {
        $credential = ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'app-123',
            'client_secret' => 'super-secret-value',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $this->assertSame('super-secret-value', $credential->client_secret);

        // Re-read from DB
        $reloaded = ServiceCredential::find($credential->id);
        $this->assertSame('super-secret-value', $reloaded->client_secret);
    }

    /** @test T008 — toPublicArray() omits the secret and includes exactly eight documented keys */
    public function toPublicArrayOmitsSecretAndIncludesEightKeys(): void
    {
        $credential = ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'app-123',
            'client_secret' => 'super-secret-value',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $public = $credential->toPublicArray();

        $expectedKeys = [
            'external_service',
            'client_id',
            'redirect_uri',
            'has_secret',
            'secret_updated_at',
            'last_verified_at',
            'last_verification_outcome',
            'version',
        ];

        $this->assertArrayKeys($expectedKeys, $public);
        $this->assertArrayNotHasKey('client_secret', $public);
        $this->assertArrayNotHasKey('id', $public);
        $this->assertArrayNotHasKey('created_at', $public);
        $this->assertArrayNotHasKey('updated_at', $public);
        $this->assertArrayNotHasKey('deleted_at', $public);

        $this->assertTrue($public['has_secret']);
        $this->assertSame('test-service', $public['external_service']);
        $this->assertSame('app-123', $public['client_id']);
        $this->assertSame('https://example.com/callback', $public['redirect_uri']);
        $this->assertSame(1, $public['version']);
    }

    /** @test T008 — toPublicArray() reports has_secret false when secret is null or empty */
    public function toPublicArrayReportsHasSecretFalseWhenEmpty(): void
    {
        $credential = ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'app-123',
            'client_secret' => '',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $public = $credential->toPublicArray();
        $this->assertFalse($public['has_secret']);
    }

    /** @test T008 — __debugInfo() renders [redacted] for client_secret */
    public function debugInfoRedactsClientSecret(): void
    {
        $credential = ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'app-123',
            'client_secret' => 'super-secret-value',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $debugInfo = $credential->__debugInfo();
        $this->assertSame('[redacted]', $debugInfo['client_secret']);
        $this->assertNotSame('super-secret-value', $debugInfo['client_secret']);
    }

    /** @test T008 — client_secret is NOT in $hidden and DOES appear in toArray() */
    public function clientSecretNotInHiddenAndAppearsInToArray(): void
    {
        $credential = ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'app-123',
            'client_secret' => 'super-secret-value',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // $hidden should not include client_secret (bridge publishes via toArray)
        $hidden = $credential->getHidden();
        $this->assertNotContains('client_secret', $hidden);

        // toArray() must include client_secret (for bridge replication)
        $array = $credential->toArray();
        $this->assertArrayHasKey('client_secret', $array);
        $this->assertSame('super-secret-value', $array['client_secret']);
    }

    /** @test T008 — scope for live credentials by service */
    public function scopeLiveByService(): void
    {
        $cred1 = ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'app-123',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // Soft-delete it
        $cred1->delete();

        $cred2 = ServiceCredential::create([
            'external_service' => 'other-service',
            'client_id' => 'app-456',
            'client_secret' => 'secret2',
            'redirect_uri' => 'https://example.com/callback2',
        ]);

        // Live scope should not return the deleted one
        $live = ServiceCredential::liveByService('test-service')->get();
        $this->assertCount(0, $live);

        $live = ServiceCredential::liveByService('other-service')->get();
        $this->assertCount(1, $live);
        $this->assertSame($cred2->id, $live->first()->id);
    }

    // ---------------------------------------------------------------- helpers

    private function assertArrayKeys(array $expected, array $actual): void
    {
        $actualKeys = array_keys($actual);
        sort($expected);
        sort($actualKeys);
        $this->assertEquals($expected, $actualKeys, 'Array keys do not match.');
    }
}
