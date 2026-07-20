<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;

/**
 * Grep src/ for google, googleapis, pageToken, and scope strings;
 * FAIL on any hit outside src/Google/ (plan §Structure Decision, research §12).
 */
class GoogleContainmentTest extends TestCase
{
    /** @test T039 — no Google identifiers leak outside src/Google/ */
    public function googleIdentifiersContainedInGoogleDirectory(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $googleDir = "$srcDir/Google";

        // Patterns that should only appear in src/Google/
        $patterns = [
            'googleapis',
            'pageToken',
            'page_token',
        ];

        // Add scope strings from ScopeBundle
        foreach (ScopeBundle::cases() as $bundle) {
            $scope = $bundle->scopeString();
            // Extract the unique part of the scope (after the base URL)
            $patterns[] = str_replace(ApiVersion::BASE_URL . '/auth/', '', $scope);
        }

        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getPathname();

            // Skip files inside src/Google/
            if (str_starts_with($filePath, $googleDir)) {
                continue;
            }

            $content = file_get_contents($filePath);

            foreach ($patterns as $pattern) {
                if (str_contains($content, $pattern)) {
                    $relativePath = str_replace($srcDir . '/', '', $filePath);
                    $violations[] = "{$relativePath}: contains '{$pattern}'";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Google identifiers found outside src/Google/: ' . implode("\n", $violations),
        );
    }

    /** @test T039 — "google" string only appears in src/Google/ and service name registration */
    public function googleStringOnlyInGoogleDirectory(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $googleDir = "$srcDir/Google";

        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getPathname();

            // Skip files inside src/Google/
            if (str_starts_with($filePath, $googleDir)) {
                continue;
            }

            $content = file_get_contents($filePath);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                // Case-insensitive check for "google"
                if (stripos($line, 'google') === false) {
                    continue;
                }

                // Allow the service name 'google-health' in the service provider
                // and in test files that reference it
                if (str_contains($line, 'google-health')
                    || str_contains($line, 'GoogleHealthService')
                    || str_contains($line, 'GoogleOauthFlow')
                    || str_contains($line, 'ScriptedGoogleTransport')
                    || str_contains($line, 'clarion-app/life-log')
                ) {
                    continue;
                }

                // Allow use statements
                if (trim($line) !== '' && str_starts_with(trim($line), 'use ')) {
                    continue;
                }

                // Allow comments that reference Google as part of integration descriptions
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                $relativePath = str_replace($srcDir . '/', '', $filePath);
                $lineNumber = $lineNum + 1;
                $violations[] = "{$relativePath}:{$lineNumber}: {$line}";
            }
        }

        $this->assertEmpty(
            $violations,
            '"google" string found outside src/Google/ (excluding allowed references): '
            . implode("\n", $violations),
        );
    }
}
