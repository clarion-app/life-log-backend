<?php

namespace ClarionApp\LifeLogBackend\Google\Api;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use GuzzleHttp\Exception\RequestException;

/**
 * Maps Google API error responses to FailureKind.
 *
 * FailureKind is closed — every Google error shape maps into an existing case.
 * A new FailureKind case appearing here would be a deliberate edit that breaks
 * FailureKindTest first.
 *
 * The mapping table from contracts/google-api.md:
 *
 * | Google response                              | → FailureKind         |
 * |----------------------------------------------|-----------------------|
 * | 401 invalid_token                            | AccessExpired         |
 * | Refresh → 400/401 invalid_grant             | declined RenewalResult|
 * | 403 ACCESS_TOKEN_SCOPE_INSUFFICIENT          | skip (not a failure)  |
 * | 403 quota / 429                              | RateLimited           |
 * | 5xx, timeout, malformed JSON                 | ServiceUnavailable    |
 * | Token endpoint rejects client id/secret     | CredentialsRejected   |
 * | 400 bad range / expired pageToken            | InvalidRequest        |
 *
 * No error message may carry a response body, access token, refresh token,
 * authorization code, or client secret.
 */
final class GoogleErrorMapper
{
    /**
     * Map a RequestException to a HealthServiceFailure.
     *
     * Returns null for 403 ACCESS_TOKEN_SCOPE_INSUFFICIENT — that is a skip
     * signal, not a failure. The caller should skip the affected type and
     * continue with the rest of the page.
     *
     * @return HealthServiceFailure|null
     */
    public static function map(RequestException $e): ?HealthServiceFailure
    {
        $response = $e->getResponse();

        if ($response === null) {
            return HealthServiceFailure::serviceUnavailable(
                'Google API request failed with no response (network error or timeout).'
            );
        }

        $status = $response->getStatusCode();
        $body = self::safeDecodeBody($response->getBody()->getContents());
        $errorReason = $body['error']['reason'] ?? $body['error'] ?? null;

        return match ($status) {
            400 => self::map400($errorReason),
            401 => self::map401($errorReason),
            403 => self::map403($errorReason),
            429 => self::map429($body),
            500, 502, 503, 504 => HealthServiceFailure::serviceUnavailable(
                "Google API returned {$status}."
            ),
            default => HealthServiceFailure::serviceUnavailable(
                "Google API returned unexpected status {$status}."
            ),
        };
    }

    /**
     * 400 — bad range or expired pageToken → InvalidRequest.
     * Token exchange 400 with invalid_grant → declined renewal (handled by caller).
     */
    private static function map400(?string $errorReason): HealthServiceFailure
    {
        return HealthServiceFailure::invalidRequest(
            match ($errorReason) {
                'invalidPageToken', 'invalid_page_token' => 'Google pageToken expired or invalid.',
                'invalidArgument', 'invalid_argument'    => 'Google rejected the request parameters.',
                default                                  => "Google returned 400.",
            }
        );
    }

    /**
     * 401 — invalid_token means AccessExpired (renew and retry).
     * Other 401 reasons are credentials issues.
     */
    private static function map401(?string $errorReason): HealthServiceFailure
    {
        return match ($errorReason) {
            'invalid_token', 'TokenExpired' => HealthServiceFailure::accessExpired(
                'Google access token expired.'
            ),
            default => HealthServiceFailure::credentialsRejected(
                'Google rejected client credentials (401).'
            ),
        };
    }

    /**
     * 403 — ACCESS_TOKEN_SCOPE_INSUFFICIENT is a skip signal (null).
     * Quota exceeded maps to RateLimited.
     */
    private static function map403(?string $errorReason): ?HealthServiceFailure
    {
        if ($errorReason === 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') {
            // This is not a failure — the grant was narrowed.
            // The caller should skip the affected type and continue.
            return null;
        }

        return match ($errorReason) {
            'rateLimitExceeded', 'quotaExceeded' => HealthServiceFailure::rateLimited(
                null,
                'Google API rate limit or quota exceeded.',
            ),
            default => HealthServiceFailure::serviceUnavailable(
                "Google returned 403 (reason: {$errorReason})."
            ),
        };
    }

    /**
     * 429 — rate limited.
     */
    private static function map429(?array $body): HealthServiceFailure
    {
        // Google may include a Retry-After header; the caller should extract it
        // from the response headers separately.
        return HealthServiceFailure::rateLimited(
            null,
            'Google API returned 429 Too Many Requests.',
        );
    }

    /**
     * Safely decode a JSON body, returning an empty array on failure.
     * Never throws — the error shape is best-effort.
     */
    private static function safeDecodeBody(string $body): ?array
    {
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
