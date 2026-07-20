<?php

namespace Tests\Support;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use Carbon\CarbonImmutable;

/**
 * A scripted ExternalHealthService for integration tests.
 *
 * Supports:
 *  - Emitting a given sequence of ResultPage objects.
 *  - Throwing a chosen HealthServiceFailure on the Nth fetch call.
 *  - Aborting mid-range (stopping cursor emission) after page N.
 *  - Surfacing late/corrected readings on a second pass (reset pages).
 *  - Recording every (since, until, cursor) tuple received, so resume
 *    correctness is an assertion about arguments.
 */
final class ScriptedSyncService implements ExternalHealthService
{
    public const NAME = 'scripted-sync';

    /** @var list<ResultPage> pages to emit in order */
    private array $pages;

    /** Throw on the Nth fetch call (1-indexed), then resume normal emission. */
    private ?int $throwOnFetch = null;

    private ?HealthServiceFailure $throwFailure = null;

    /** Stop emitting cursors after this page index (0-indexed). */
    private ?int $abortAfterPage = null;

    /** Current fetch call count (1-indexed). */
    private int $fetchCallCount = 0;

    /** Current page index being emitted. */
    private int $currentPageIndex = 0;

    /**
     * @var list<array{since: string, until: string, cursor: ?string}>
     */
    public array $fetchCalls = [];

    /** @var list<string> userIds passed to renewAccess */
    public array $renewCalls = [];

    /**
     * @param  list<ResultPage>  $pages
     */
    public function __construct(
        array $pages = [],
    ) {
        $this->pages = $pages;
    }

    /**
     * Create a service that emits the given pages.
     *
     * @param  list<ResultPage>  $pages
     */
    public static function emitting(array $pages): self
    {
        return new self($pages);
    }

    /**
     * Create a service that emits pages with cursors, simulating paging.
     *
     * @param  list<array{measurements: int, sessions: int}>  $pageSpecs
     */
    public static function withPages(array $pageSpecs): self
    {
        $pages = [];
        $total = count($pageSpecs);
        $baseTime = CarbonImmutable::now();

        foreach ($pageSpecs as $i => $spec) {
            $measurements = [];
            for ($m = 0; $m < ($spec['measurements'] ?? 0); $m++) {
                $measurements[] = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                    userId: 'test-user',
                    type: \ClarionApp\LifeLogBackend\Vocabulary\MeasurementType::Steps,
                    value: (string) (1000 + $i * 100 + $m),
                    recordedAt: $baseTime->addMinutes($i * 10 + $m),
                    externalId: sprintf('meas-%d-%d', $i, $m),
                    externalService: self::NAME,
                );
            }

            $sessions = [];
            for ($s = 0; $s < ($spec['sessions'] ?? 0); $s++) {
                $sessions[] = \ClarionApp\LifeLogBackend\External\TranslatedSession::make(
                    userId: 'test-user',
                    type: \ClarionApp\LifeLogBackend\Vocabulary\SessionType::Sleep,
                    startedAt: $baseTime->addHours($i * 24),
                    endedAt: $baseTime->addHours($i * 24 + 8),
                    summaryValues: ['duration' => '28800.0000'],
                    externalId: sprintf('sess-%d-%d', $i, $s),
                    externalService: self::NAME,
                );
            }

            $nextCursor = ($i < $total - 1)
                ? new PageCursor(sprintf('cursor-%d', $i + 1))
                : null;

            $pages[] = new ResultPage($measurements, $sessions, $nextCursor);
        }

        return new self($pages);
    }

    /** Throw $failure on the Nth fetch call (1-indexed), then resume normal emission. */
    public function throwOn(int $n, HealthServiceFailure $failure): self
    {
        $this->throwOnFetch = $n;
        $this->throwFailure = $failure;
        return $this;
    }

    /** Convenience: throw ServiceUnavailable on the Nth fetch. */
    public function throwServiceUnavailableOn(int $n): self
    {
        return $this->throwOn($n, HealthServiceFailure::serviceUnavailable('service is down'));
    }

    /** Convenience: throw RateLimited on the Nth fetch. */
    public function throwRateLimitedOn(int $n, ?int $retryAfterSeconds = null): self
    {
        return $this->throwOn($n, HealthServiceFailure::rateLimited($retryAfterSeconds, 'rate limited'));
    }

    /** Convenience: throw AccessExpired on the Nth fetch. */
    public function throwAccessExpiredOn(int $n): self
    {
        return $this->throwOn($n, HealthServiceFailure::accessExpired('token expired'));
    }

    /** Convenience: throw AccessRevoked on the Nth fetch. */
    public function throwAccessRevokedOn(int $n): self
    {
        return $this->throwOn($n, HealthServiceFailure::accessRevoked('access revoked'));
    }

    /** Convenience: throw CredentialsRejected on the Nth fetch. */
    public function throwCredentialsRejectedOn(int $n): self
    {
        return $this->throwOn($n, HealthServiceFailure::credentialsRejected('our credentials rejected'));
    }

    /** Convenience: throw InvalidRequest on the Nth fetch. */
    public function throwInvalidRequestOn(int $n): self
    {
        return $this->throwOn($n, HealthServiceFailure::invalidRequest('bad request'));
    }

    /** Stop emitting cursors after page index $idx (0-indexed), simulating mid-range abort. */
    public function abortAfterPage(int $idx): self
    {
        $this->abortAfterPage = $idx;
        return $this;
    }

    /** Grant to return from completeConnection, or null to use default. */
    private ?AuthorizationGrant $completeConnectionGrant = null;

    /** Exception to throw from completeConnection, or null for success. */
    private ?HealthServiceFailure $completeConnectionFailure = null;

    /** @var list<array{userId: string, code: string, redirectUri: string}> */
    public array $completeConnectionCalls = [];

    /** Script completeConnection to return a specific grant. */
    public function completeConnectionWith(AuthorizationGrant $grant): self
    {
        $this->completeConnectionGrant = $grant;
        $this->completeConnectionFailure = null;
        return $this;
    }

    /** Script completeConnection to throw CredentialsRejected. */
    public function completeConnectionThrowsCredentialsRejected(): self
    {
        $this->completeConnectionFailure = HealthServiceFailure::credentialsRejected('credentials rejected');
        $this->completeConnectionGrant = null;
        return $this;
    }

    /** Script completeConnection to throw InvalidRequest. */
    public function completeConnectionThrowsInvalidRequest(): self
    {
        $this->completeConnectionFailure = HealthServiceFailure::invalidRequest('invalid request');
        $this->completeConnectionGrant = null;
        return $this;
    }

    /**
     * Replace the page sequence (simulates a second pass with late/corrected data).
     * Resets the page index but keeps the call count.
     *
     * @param  list<ResultPage>  $pages
     */
    public function resetPages(array $pages): self
    {
        $this->pages = $pages;
        $this->currentPageIndex = 0;
        return $this;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supportedTypes(): array
    {
        return [];
    }

    public function beginConnection(string $userId): ConnectionResult
    {
        // A real 32-byte-minimum state, not a fixed short literal — matching
        // what any conformant implementation must produce. ConnectionAttemptFactory
        // rejects anything under 32 bytes of entropy (research §4), and this
        // double is exercised through that same gate whenever a test drives
        // the real POST /connected-accounts endpoint (quickstart.md §3, T093).
        $state = base64_encode(random_bytes(32));

        return new ConnectionResult(
            externalService: self::NAME,
            authorizationUrl: 'https://scripted.example/authorize?client_id=demo-client&state=' . urlencode($state),
            state: $state,
        );
    }

    public function fetch(
        string $userId,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?PageCursor $cursor = null,
    ): ResultPage {
        $this->fetchCallCount++;

        $this->fetchCalls[] = [
            'since' => $since->toISOString(),
            'until' => $until->toISOString(),
            'cursor' => $cursor?->toString(),
        ];

        // Throw on the Nth call
        if ($this->throwOnFetch !== null && $this->fetchCallCount === $this->throwOnFetch) {
            $failure = $this->throwFailure
                ?? HealthServiceFailure::serviceUnavailable('scripted failure');
            // Clear so subsequent calls resume normally
            $this->throwOnFetch = null;
            $this->throwFailure = null;
            throw $failure;
        }

        if ($this->currentPageIndex >= count($this->pages)) {
            // Exhausted — return empty page with no cursor
            return new ResultPage();
        }

        $page = $this->pages[$this->currentPageIndex];
        $this->currentPageIndex++;

        // If abortAfterPage is set and we just emitted that page, return a page
        // with no cursor (simulating the service not giving us a next cursor)
        if ($this->abortAfterPage !== null && ($this->currentPageIndex - 1) === $this->abortAfterPage) {
            return new ResultPage(
                $page->measurements(),
                $page->sessions(),
                null,
            );
        }

        return $page;
    }

    /** Result to return from renewAccess(), or null to use the default (always renews). */
    private ?RenewalResult $renewAccessResult = null;

    /** Exception to throw from renewAccess(), or null for the default flow. */
    private ?HealthServiceFailure $renewAccessFailure = null;

    /** Script renewAccess() to decline — a permanent refusal (FR-017). */
    public function renewAccessDeclines(): self
    {
        $this->renewAccessResult = RenewalResult::declined();
        $this->renewAccessFailure = null;
        return $this;
    }

    /** Script renewAccess() to throw, simulating a merely transient failure. */
    public function renewAccessThrows(HealthServiceFailure $failure): self
    {
        $this->renewAccessFailure = $failure;
        $this->renewAccessResult = null;
        return $this;
    }

    public function renewAccess(string $userId): RenewalResult
    {
        $this->renewCalls[] = $userId;

        if ($this->renewAccessFailure !== null) {
            throw $this->renewAccessFailure;
        }

        return $this->renewAccessResult ?? RenewalResult::renewed(CarbonImmutable::now()->addHours(8));
    }

    /** Result to return from disconnect(), or null to use the default. */
    private ?DisconnectResult $disconnectResult = null;

    /** Exception to throw from disconnect(), or null for success. */
    private ?\Throwable $disconnectFailure = null;

    /** @var list<string> userIds passed to disconnect */
    public array $disconnectCalls = [];

    /** Script disconnect() to return a specific result. */
    public function disconnectReturns(DisconnectResult $result): self
    {
        $this->disconnectResult = $result;
        $this->disconnectFailure = null;
        return $this;
    }

    /** Script disconnect() to throw, simulating an unreachable or erroring provider. */
    public function disconnectThrows(\Throwable $failure): self
    {
        $this->disconnectFailure = $failure;
        $this->disconnectResult = null;
        return $this;
    }

    public function disconnect(string $userId): DisconnectResult
    {
        $this->disconnectCalls[] = $userId;

        if ($this->disconnectFailure !== null) {
            throw $this->disconnectFailure;
        }

        return $this->disconnectResult ?? DisconnectResult::confirmed();
    }

    public function completeConnection(
        string $userId,
        string $code,
        string $redirectUri,
    ): AuthorizationGrant {
        $this->completeConnectionCalls[] = [
            'userId' => $userId,
            'code' => $code,
            'redirectUri' => $redirectUri,
        ];

        if ($this->completeConnectionFailure !== null) {
            throw $this->completeConnectionFailure;
        }

        if ($this->completeConnectionGrant !== null) {
            return $this->completeConnectionGrant;
        }

        // Default: return a standard grant
        return new AuthorizationGrant(
            accessToken: 'access-token-for-' . $userId,
            refreshToken: 'refresh-token-for-' . $userId,
            expiresAt: CarbonImmutable::now()->addHours(2),
            scopes: 'read:health_data',
            externalAccountId: 'ext-' . $userId,
        );
    }

    /** Get the recorded fetch calls. */
    public function getFetchCalls(): array
    {
        return $this->fetchCalls;
    }

    /** Get the total number of fetch calls made. */
    public function getFetchCallCount(): int
    {
        return $this->fetchCallCount;
    }
}
