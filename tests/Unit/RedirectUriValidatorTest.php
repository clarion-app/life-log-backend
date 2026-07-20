<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Connection\RedirectUriValidator;

class RedirectUriValidatorTest extends TestCase
{
    protected RedirectUriValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RedirectUriValidator();
    }

    // ── Acceptance ──

    /** @test T027 — accepts absolute https URIs */
    public function acceptsAbsoluteHttpsUris(): void
    {
        $this->assertTrue($this->validator->isValid('https://example.com/callback'));
        $this->assertTrue($this->validator->isValid('https://example.com:8443/callback'));
        $this->assertTrue($this->validator->isValid('https://example.com'));
        $this->assertTrue($this->validator->isValid('https://example.com/path/to/callback'));
    }

    /** @test T027 — accepts http only for localhost / 127.0.0.1 */
    public function acceptsHttpOnlyForLocalhost(): void
    {
        $this->assertTrue($this->validator->isValid('http://localhost/callback'));
        $this->assertTrue($this->validator->isValid('http://localhost:8080/callback'));
        $this->assertTrue($this->validator->isValid('http://127.0.0.1/callback'));
        $this->assertTrue($this->validator->isValid('http://127.0.0.1:3000/callback'));
    }

    // ── Rejections ──

    /** @test T027 — rejects http for non-localhost hosts */
    public function rejectsHttpForNonLocalhost(): void
    {
        $this->assertFalse($this->validator->isValid('http://example.com/callback'));
        $this->assertFalse($this->validator->isValid('http://node.example/callback'));
        $this->assertFalse($this->validator->isValid('http://192.168.1.1/callback'));
    }

    /** @test T027 — rejects wildcards */
    public function rejectsWildcards(): void
    {
        $this->assertFalse($this->validator->isValid('https://*.example.com/callback'));
        $this->assertFalse($this->validator->isValid('https://example.com/*/callback'));
        $this->assertFalse($this->validator->isValid('https://example.com/callback/*'));
    }

    /** @test T027 — rejects userinfo component */
    public function rejectsUserInfo(): void
    {
        $this->assertFalse($this->validator->isValid('https://user@example.com/callback'));
        $this->assertFalse($this->validator->isValid('https://user:pass@example.com/callback'));
    }

    /** @test T027 — rejects fragment */
    public function rejectsFragment(): void
    {
        $this->assertFalse($this->validator->isValid('https://example.com/callback#section'));
    }

    /** @test T027 — rejects query string */
    public function rejectsQuery(): void
    {
        $this->assertFalse($this->validator->isValid('https://example.com/callback?foo=bar'));
    }

    /** @test T027 — rejects relative URIs */
    public function rejectsRelativeUris(): void
    {
        $this->assertFalse($this->validator->isValid('/callback'));
        $this->assertFalse($this->validator->isValid('callback'));
        $this->assertFalse($this->validator->isValid('example.com/callback'));
    }

    // ── Canonicalisation ──

    /** @test T027 — canonicalisation lowercases scheme and host */
    public function canonicalisationLowercasesSchemeAndHost(): void
    {
        $result = $this->validator->canonicalise('HTTPS://EXAMPLE.COM/callback');
        $this->assertSame('https://example.com/callback', $result);
    }

    /** @test T027 — canonicalisation strips default port */
    public function canonicalisationStripsDefaultPort(): void
    {
        $result = $this->validator->canonicalise('https://example.com:443/callback');
        $this->assertSame('https://example.com/callback', $result);

        $result = $this->validator->canonicalise('http://localhost:80/callback');
        $this->assertSame('http://localhost/callback', $result);
    }

    /** @test T027 — canonicalisation preserves non-default port */
    public function canonicalisationPreservesNonDefaultPort(): void
    {
        $result = $this->validator->canonicalise('https://example.com:8443/callback');
        $this->assertSame('https://example.com:8443/callback', $result);
    }

    // ── Exact equality comparison ──

    /** @test T027 — matchesExact returns true for identical URIs */
    public function matchesExactReturnsTrueForIdenticalUris(): void
    {
        $this->assertTrue(
            $this->validator->matchesExact('https://example.com/callback', 'https://example.com/callback'),
        );
    }

    /** @test T027 — matchesExact is case-insensitive for scheme and host */
    public function matchesExactIsCaseInsensitive(): void
    {
        $this->assertTrue(
            $this->validator->matchesExact('HTTPS://EXAMPLE.COM/callback', 'https://example.com/callback'),
        );
    }

    /** @test T027 — matchesExact rejects prefix-match near-misses */
    public function matchesExactRejectsPrefixMatchNearMisses(): void
    {
        $this->assertFalse(
            $this->validator->matchesExact(
                'https://good.example.com.evil.test/',
                'https://good.example.com/callback',
            ),
        );
    }

    /** @test T027 — matchesExact rejects path differences */
    public function matchesExactRejectsPathDifferences(): void
    {
        $this->assertFalse(
            $this->validator->matchesExact(
                'https://example.com/callback/extra',
                'https://example.com/callback',
            ),
        );
    }

    /** @test T027 — matchesExact handles port stripping */
    public function matchesExactHandlesPortStripping(): void
    {
        $this->assertTrue(
            $this->validator->matchesExact(
                'https://example.com:443/callback',
                'https://example.com/callback',
            ),
        );
    }
}
