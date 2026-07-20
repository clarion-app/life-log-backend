<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Sync\NeedsAttentionReason;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;

class NeedsAttentionReasonTest extends TestCase
{
    /** @test T019b — NeedsAttentionReason has exactly four values */
    public function needsAttentionReasonHasFourValues(): void
    {
        $values = NeedsAttentionReason::cases();
        $this->assertCount(4, $values);

        $valueNames = array_map(fn ($case) => $case->value, $values);
        $expected = [
            'credential_rotated',
            'credential_removed',
            'authorization_unrenewable',
            'sync_failures',
        ];
        sort($valueNames);
        sort($expected);
        $this->assertEquals($expected, $valueNames);
    }

    /** @test T019b — NeedsAttentionReason cases are accessible by name */
    public function needsAttentionReasonCasesAccessibleByName(): void
    {
        $this->assertSame('credential_rotated', NeedsAttentionReason::CredentialRotated->value);
        $this->assertSame('credential_removed', NeedsAttentionReason::CredentialRemoved->value);
        $this->assertSame('authorization_unrenewable', NeedsAttentionReason::AuthorizationUnrenewable->value);
        $this->assertSame('sync_failures', NeedsAttentionReason::SyncFailures->value);
    }

    /** @test T019b — FailureKind is unchanged (still six cases) */
    public function failureKindIsUnchanged(): void
    {
        $values = FailureKind::cases();
        $this->assertCount(6, $values, 'FailureKind should remain a closed set of six cases.');

        $valueNames = array_map(fn ($case) => $case->value, $values);
        $expected = [
            'access_expired',
            'access_revoked',
            'credentials_rejected',
            'rate_limited',
            'service_unavailable',
            'invalid_request',
        ];
        sort($valueNames);
        sort($expected);
        $this->assertEquals($expected, $valueNames, 'FailureKind cases should be unchanged.');
    }

    /** @test T019b — NeedsAttentionReason is distinct from FailureKind */
    public function needsAttentionReasonIsDistinctFromFailureKind(): void
    {
        // No NeedsAttentionReason value should match a FailureKind value
        $reasonValues = array_map(fn ($case) => $case->value, NeedsAttentionReason::cases());
        $failureValues = array_map(fn ($case) => $case->value, FailureKind::cases());

        $intersection = array_intersect($reasonValues, $failureValues);
        $this->assertEmpty(
            $intersection,
            'NeedsAttentionReason and FailureKind should have no overlapping values. '
            . 'Found overlap: ' . implode(', ', $intersection),
        );
    }
}
