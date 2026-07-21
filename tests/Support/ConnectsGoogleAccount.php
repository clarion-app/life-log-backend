<?php

namespace Tests\Support;

use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Carbon\CarbonImmutable;

/**
 * A connected Google account with a live authorization.
 *
 * GoogleHealthService reads its access token from the stored authorization
 * row, which is keyed by connected_account_id — so a test that fetches without
 * both rows present is testing the "not connected" path, not the one it means
 * to. Shared here so every Google integration test agrees on that setup.
 */
trait ConnectsGoogleAccount
{
    protected function connectGoogleAccount(
        string $userId,
        string $accessToken = 'test-access-token',
        string $refreshToken = 'test-refresh-token',
        ?CarbonImmutable $expiresAt = null,
    ): ConnectedAccount {
        $account = ConnectedAccount::create([
            'user_id'          => $userId,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'test-external-user-id',
            'sync_state'       => 'normal',
            'connected_at'     => CarbonImmutable::now(),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token'         => $accessToken,
            'refresh_token'        => $refreshToken,
            'expires_at'           => $expiresAt ?? CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        return $account;
    }
}
