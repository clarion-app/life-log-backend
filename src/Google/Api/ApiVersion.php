<?php

namespace ClarionApp\LifeLogBackend\Google\Api;

/**
 * Version pin for the Google Health API.
 *
 * GoogleHealthClient is the only place a URL is constructed, and it reads
 * the pin from here. Changing VERSION is a deliberate edit that fails
 * ApiVersionPinTest first — the pinned string must appear in every URI
 * the client constructs.
 *
 * Verified 2026-07-20 against Google Health API documentation.
 * - API version: v1 (current stable)
 * - Base URL: https://health.googleapis.com
 * - OAuth endpoints: https://accounts.google.com/o/oauth2/v2/auth (authorize),
 *   https://oauth2.googleapis.com/token (token exchange),
 *   https://oauth2.googleapis.com/revoke (revoke)
 * - Query ranges: 14 days heart rate, 90 days everything else
 * - Page cap: 10,000 data points per page
 * - Rate limits: 300/min/user, 120k/min project, 86.4M/day project
 */
final class ApiVersion
{
    public const VERSION     = 'v1';
    public const BASE_URL    = 'https://health.googleapis.com';
    public const VERIFIED_ON = '2026-07-20';

    /** OAuth authorize endpoint — Google identity, not Health API. */
    public const OAUTH_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** OAuth token exchange endpoint. */
    public const OAUTH_TOKEN = 'https://oauth2.googleapis.com/token';

    /** OAuth token revocation endpoint. */
    public const OAUTH_REVOKE = 'https://oauth2.googleapis.com/revoke';
}
