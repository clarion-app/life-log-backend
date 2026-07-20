<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every endpoint in this feature refuses an unauthenticated caller with
 * 401 — including the consent return callback, which claims no exception
 * (FR-014a, FR-024, FR-025).
 *
 * This covers the endpoint surface that exists today. The remaining
 * endpoints are folded in as they land.
 */
class UnauthenticatedAccessDenialTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    protected function base(): string
    {
        return '/api/clarion-app/life-log';
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function endpointProvider(): array
    {
        $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        return [
            'credentials index'   => ['get', 'service-credentials', []],
            'credentials store'   => ['post', 'service-credentials', [
                'external_service' => 'fake-step',
                'client_id' => 'cid',
                'client_secret' => 'secret',
                'redirect_uri' => 'https://example.com/callback',
            ]],
            'credentials update'  => ['put', 'service-credentials/fake-step', [
                'client_secret' => 'secret',
            ]],
            'credentials verify'  => ['post', 'service-credentials/fake-step/verify', []],
            'credentials destroy' => ['delete', 'service-credentials/fake-step', []],
            'connection begin'    => ['post', 'connected-accounts', [
                'external_service' => 'fake-step',
            ]],
            'connection callback' => ['post', 'connected-accounts/callback', [
                'external_service' => 'fake-step',
                'state' => 'some-state-value',
                'code' => 'some-code',
                'redirect_uri' => 'https://example.com/callback',
            ]],
            'connection sync'     => ['post', "connected-accounts/{$id}/sync", []],
            'connection show'     => ['get', "connected-accounts/{$id}", []],
        ];
    }

    /**
     * @dataProvider endpointProvider
     *
     * @param array<string, mixed> $payload
     */
    public function testEndpointRefusesUnauthenticatedCaller(string $method, string $path, array $payload): void
    {
        $response = $this->json(strtoupper($method), $this->base()."/{$path}", $payload);

        $this->assertSame(
            401,
            $response->getStatusCode(),
            "{$method} {$path} should refuse an unauthenticated caller with 401."
        );
    }

    /** @test */
    public function theCallbackClaimsNoUnauthenticatedException()
    {
        $response = $this->postJson($this->base().'/connected-accounts/callback', [
            'external_service' => 'fake-step',
            'state' => (string) Str::uuid(),
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(401);
    }
}
