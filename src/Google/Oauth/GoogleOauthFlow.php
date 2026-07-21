<?php

namespace ClarionApp\LifeLogBackend\Google\Oauth;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Exceptions\ServiceNotConfiguredException;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;

/**
 * Google OAuth 2.0 flow: authorize URL, code exchange, token refresh.
 *
 * Reads credentials via ServiceCredentialProvider on every call — never
 * caches a credential across method calls. HealthServiceRegistry memoises
 * the service instance, so a credential read in a constructor would survive
 * every rotation for the life of the worker process.
 *
 * Never logs or returns a code, token, or secret.
 */
final class GoogleOauthFlow
{
    public function __construct(
        private ServiceCredentialProvider $credentialProvider,
        private Client $http,
    ) {
    }

    /**
     * Build the Google consent URL for offline access with three scope bundles.
     *
     * @return array{url: string, state: string}
     */
    public function authorizeUrl(string $userId, string $redirectUri): array
    {
        $state = bin2hex(random_bytes(32));
        $scopes = collect(ScopeBundle::all())
            ->map(fn ($b) => $b->scopeString())
            ->implode('+');

        $params = http_build_query([
            'client_id'     => $this->credentialProvider->require('google-health')->client_id,
            'redirect_uri'  => $redirectUri,
            'scope'         => $scopes,
            'response_type' => 'code',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);

        return [
            'url'   => ApiVersion::OAUTH_AUTHORIZE . '?' . $params,
            'state' => $state,
        ];
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int, scopes: list<string>}
     *
     * @throws HealthServiceFailure CredentialsRejected when Google rejects the
     *         client id/secret; InvalidRequest when the code is malformed or
     *         already redeemed.
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $credential = $this->credentialProvider->require('google-health');

        try {
            $response = $this->http->post(ApiVersion::OAUTH_TOKEN, [
                'form_params' => [
                    'client_id'     => $credential->client_id,
                    'client_secret' => $credential->client_secret,
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $redirectUri,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw $this->mapTokenError($e);
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (!is_array($body) || !isset($body['access_token'])) {
            throw HealthServiceFailure::invalidRequest(
                'Google token exchange returned an unexpected response shape.'
            );
        }

        // Google returns space-separated scope strings; split into array.
        $rawScopes = $body['scope'] ?? '';
        $scopes = $rawScopes !== '' ? explode(' ', $rawScopes) : [];

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_in'    => (int) ($body['expires_in'] ?? 3600),
            'scopes'        => $scopes,
        ];
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{access_token: string, expires_in: int}
     *
     * @throws HealthServiceFailure AccessRevoked when the refresh token is no
     *         longer valid (user revoked, testing expiry, project deleted);
     *         CredentialsRejected when Google rejects the client credentials.
     */
    public function refreshToken(string $refreshToken): array
    {
        $credential = $this->credentialProvider->require('google-health');

        try {
            $response = $this->http->post(ApiVersion::OAUTH_TOKEN, [
                'form_params' => [
                    'client_id'     => $credential->client_id,
                    'client_secret' => $credential->client_secret,
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw $this->mapTokenError($e);
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (!is_array($body) || !isset($body['access_token'])) {
            throw HealthServiceFailure::accessRevoked(
                'Google token refresh returned an unexpected response shape.'
            );
        }

        return [
            'access_token' => $body['access_token'],
            'expires_in'   => (int) ($body['expires_in'] ?? 3600),
        ];
    }

    /**
     * Get the current access token for a user from the stored authorization.
     *
     * Reads the authorization row on every call — never caches.
     *
     * @throws ServiceNotConfiguredException if no authorization exists
     */
    public function getAccessToken(string $userId): string
    {
        $authorization = \ClarionApp\LifeLogBackend\Models\AccountAuthorization::where(
            'connected_account_id',
            $this->findAccountId($userId),
        )->first();

        if ($authorization === null || $authorization->access_token === null) {
            throw new ServiceNotConfiguredException(
                "No access token found for user {$userId}."
            );
        }

        return $authorization->access_token;
    }

    /**
     * Revoke a token (best-effort).
     *
     * @return bool true if the remote call succeeded
     */
    public function revoke(string $token): bool
    {
        try {
            $this->http->post(ApiVersion::OAUTH_REVOKE, [
                'form_params' => ['token' => $token],
            ]);
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Find the connected account id for a user by their user_id.
     *
     * @throws ServiceNotConfiguredException if no account exists
     */
    private function findAccountId(string $userId): string
    {
        $account = \ClarionApp\LifeLogBackend\Models\ConnectedAccount::where('user_id', $userId)
            ->where('external_service', 'google-health')
            ->first();

        if ($account === null) {
            throw new ServiceNotConfiguredException(
                "No connected Google account found for user {$userId}."
            );
        }

        return $account->id;
    }

    /**
     * Map a Guzzle exception from the token endpoint to HealthServiceFailure.
     */
    private function mapTokenError(GuzzleException $e): HealthServiceFailure
    {
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
        $body = '';

        if ($e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
        }

        $error = $body['error'] ?? '';

        if ($status === 400 && in_array($error, ['invalid_grant', 'invalid_client'], true)) {
            // invalid_grant: refresh token revoked/expired; invalid_client: bad credentials
            return $error === 'invalid_client'
                ? HealthServiceFailure::credentialsRejected('Google rejected client credentials during token operation.')
                : HealthServiceFailure::accessRevoked('Google refresh token is no longer valid.');
        }

        if ($status === 400) {
            return HealthServiceFailure::invalidRequest(
                'Google token endpoint returned a 400 error.'
            );
        }

        if ($status >= 500) {
            return HealthServiceFailure::serviceUnavailable(
                'Google token endpoint returned a ' . $status . ' error.'
            );
        }

        return HealthServiceFailure::serviceUnavailable(
            'Google token endpoint request failed.'
        );
    }
}
