<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Google\Api\GoogleErrorMapper;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Full error mapping table; no new FailureKind values introduced.
 */
class GoogleErrorMapperTest extends TestCase
{
    /** @test T050 — 401 invalid_token maps to AccessExpired */
    public function fourZeroOneInvalidTokenMapsToAccessExpired(): void
    {
        $response = new Response(401, [], json_encode([
            'error' => [
                'message' => 'Invalid Credentials',
                'status'  => 'UNAUTHENTICATED',
                'reason'  => 'invalid_token',
            ],
        ]));

        $exception = new RequestException('Unauthorized', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::AccessExpired, $failure->kind);
    }

    /** @test T050 — 403 ACCESS_TOKEN_SCOPE_INSUFFICIENT returns null (skip signal) */
    public function fourZeroThreeScopeInsufficientReturnsNull(): void
    {
        $response = new Response(403, [], json_encode([
            'error' => [
                'message' => 'The access token has insufficient scope.',
                'status'  => 'PERMISSION_DENIED',
                'reason'  => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
            ],
        ]));

        $exception = new RequestException('Forbidden', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertNull($failure);
    }

    /** @test T050 — 403 quota exceeded maps to RateLimited */
    public function fourZeroThreeQuotaExceededMapsToRateLimited(): void
    {
        $response = new Response(403, [], json_encode([
            'error' => [
                'message' => 'Quota exceeded',
                'status'  => 'PERMISSION_DENIED',
                'reason'  => 'quotaExceeded',
            ],
        ]));

        $exception = new RequestException('Forbidden', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::RateLimited, $failure->kind);
    }

    /** @test T050 — 429 maps to RateLimited */
    public function fourTwoNineMapsToRateLimited(): void
    {
        $response = new Response(429, [], json_encode([
            'error' => [
                'message' => 'Too Many Requests',
                'status'  => 'RESOURCE_EXHAUSTED',
            ],
        ]));

        $exception = new RequestException('Too Many Requests', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::RateLimited, $failure->kind);
    }

    /** @test T050 — 500 maps to ServiceUnavailable */
    public function fiveHundredMapsToServiceUnavailable(): void
    {
        $response = new Response(500, [], json_encode([
            'error' => [
                'message' => 'Internal Server Error',
                'status'  => 'INTERNAL',
            ],
        ]));

        $exception = new RequestException('Internal Server Error', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::ServiceUnavailable, $failure->kind);
    }

    /** @test T050 — 503 maps to ServiceUnavailable */
    public function fiveZeroThreeMapsToServiceUnavailable(): void
    {
        $response = new Response(503, [], json_encode([
            'error' => [
                'message' => 'Service Unavailable',
                'status'  => 'UNAVAILABLE',
            ],
        ]));

        $exception = new RequestException('Service Unavailable', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::ServiceUnavailable, $failure->kind);
    }

    /** @test T050 — 400 maps to InvalidRequest */
    public function fourHundredMapsToInvalidRequest(): void
    {
        $response = new Response(400, [], json_encode([
            'error' => [
                'message' => 'Bad Request',
                'status'  => 'INVALID_ARGUMENT',
                'reason'  => 'invalidArgument',
            ],
        ]));

        $exception = new RequestException('Bad Request', new Request('GET', '/test'), $response);
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::InvalidRequest, $failure->kind);
    }

    /** @test T050 — network error (no response) maps to ServiceUnavailable */
    public function networkErrorMapsToServiceUnavailable(): void
    {
        // RequestException with no response simulates a network error
        $exception = new RequestException('Connection refused', new Request('GET', '/test'));
        $failure = GoogleErrorMapper::map($exception);

        $this->assertInstanceOf(HealthServiceFailure::class, $failure);
        $this->assertSame(FailureKind::ServiceUnavailable, $failure->kind);
    }

    /** @test T050 — no new FailureKind values introduced */
    public function noNewFailureKindValues(): void
    {
        // Verify the closed enum has exactly the expected values
        $expectedKinds = [
            FailureKind::AccessExpired,
            FailureKind::AccessRevoked,
            FailureKind::CredentialsRejected,
            FailureKind::RateLimited,
            FailureKind::ServiceUnavailable,
            FailureKind::InvalidRequest,
        ];

        $actualKinds = FailureKind::cases();
        $this->assertCount(count($expectedKinds), $actualKinds);

        foreach ($expectedKinds as $expected) {
            $this->assertContains($expected, $actualKinds);
        }
    }
}
