<?php

namespace ClarionApp\LifeLogBackend\Connection;

/**
 * Validates and canonicalises redirect URIs for OAuth flows.
 *
 * Enforces exact-equality comparison (research §7) to prevent prefix-match
 * bypasses like https://good.example.com.evil.test/.
 */
final class RedirectUriValidator
{
    /**
     * Check whether a redirect URI is valid at store time.
     *
     * Rules:
     *  - Absolute URI with https scheme (http allowed for localhost/127.0.0.1 only)
     *  - No wildcard characters
     *  - No userinfo component
     *  - No fragment
     *  - No query string
     */
    public function isValid(string $uri): bool
    {
        $parsed = parse_url($uri);

        // Must have scheme and host
        if (!isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);

        // Scheme must be https or http
        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }

        // http is only allowed for localhost / 127.0.0.1
        if ($scheme === 'http') {
            $host = strtolower($parsed['host']);
            if ($host !== 'localhost' && $host !== '127.0.0.1') {
                return false;
            }
        }

        // No wildcard characters
        if (str_contains($uri, '*')) {
            return false;
        }

        // No userinfo component
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        // No fragment
        if (isset($parsed['fragment'])) {
            return false;
        }

        // No query string
        if (isset($parsed['query'])) {
            return false;
        }

        return true;
    }

    /**
     * Canonicalise a redirect URI for storage and comparison.
     *
     * - Lowercases scheme and host
     * - Strips default ports (443 for https, 80 for http)
     * - Preserves path exactly
     */
    public function canonicalise(string $uri): string
    {
        $parsed = parse_url($uri);

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);
        $port = $parsed['port'] ?? null;
        $path = $parsed['path'] ?? '/';

        // Strip default ports
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        $result = "{$scheme}://{$host}";
        if ($port !== null) {
            $result .= ":{$port}";
        }
        $result .= $path;

        return $result;
    }

    /**
     * Check whether two redirect URIs match exactly after canonicalisation.
     *
     * Uses byte-identical comparison on the canonicalised forms.
     * This prevents prefix-match bypasses like
     * https://good.example.com.evil.test/ matching https://good.example.com/.
     */
    public function matchesExact(string $submitted, string $registered): bool
    {
        $submittedCanonical = $this->canonicalise($submitted);
        $registeredCanonical = $this->canonicalise($registered);

        return hash_equals($registeredCanonical, $submittedCanonical);
    }
}
