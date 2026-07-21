<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Guzzle MockHandler + History middleware seam for Google API integration tests.
 *
 * Scripts HTTP responses and captures request metadata so every Google
 * interaction in the test suite is deterministic and offline.
 *
 * Modelled on llm-client/tests/Integration/Harness/ScriptedTransport.php (053).
 */
class ScriptedGoogleTransport
{
    /** @var array<int, array{response: Response}> Guzzle history entries */
    private array $history;

    private MockHandler $mock;
    private HandlerStack $stack;

    /**
     * Static factory — starts with an empty script.
     */
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
        $this->history = [];
        $this->mock = new MockHandler();
        $this->stack = HandlerStack::create($this->mock);
        $this->stack->push(Middleware::history($this->history), 'history');
    }

    /**
     * Queue a JSON response with the given status code.
     */
    public function respondJson(int $status, array|string $body): self
    {
        $this->mock->append(new Response(
            $status,
            ['Content-Type' => 'application/json'],
            is_string($body) ? $body : json_encode($body)
        ));
        return $this;
    }

    /**
     * Queue a response with the given status code and an empty body.
     */
    public function respondStatus(int $status): self
    {
        $this->mock->append(new Response($status));
        return $this;
    }

    /**
     * Install the handler stack into the Laravel container as a Guzzle Client.
     *
     * Binds 'guzzle' as a singleton so the product code resolves it via
     * $app->make('guzzle') or $app->make(Client::class).
     */
    public function bind(Container $app): void
    {
        $client = new Client(['handler' => $this->stack]);
        $app->instance(Client::class, $client);
        $app->instance('guzzle', $client);
    }

    /**
     * Number of requests that have been captured by the History middleware.
     */
    public function requestCount(): int
    {
        return count($this->history);
    }

    /**
     * The URI of the most recent captured request (or null if none).
     */
    public function lastUri(): ?string
    {
        if (empty($this->history)) {
            return null;
        }
        $last = end($this->history);
        return (string) $last['request']->getUri();
    }

    /**
     * All captured requests as structured tuples.
     *
     * @return array<int, array{method: string, uri: string, query: string, headers: array<string, array<int, string>>}>
     */
    public function requests(): array
    {
        $result = [];
        foreach ($this->history as $entry) {
            $request = $entry['request'];
            $uri = $request->getUri();
            $result[] = [
                'method'  => $request->getMethod(),
                'uri'     => (string) $uri,
                'query'   => $uri->getQuery() ?? '',
                'headers' => $request->getHeaders(),
            ];
        }
        return $result;
    }

    /**
     * Clear all scripted responses and start fresh.
     * Re-binds the new handler stack into the container.
     */
    public function clearResponses(Container $app): void
    {
        $this->history = [];
        $this->mock = new MockHandler();
        $this->stack = HandlerStack::create($this->mock);
        $this->stack->push(Middleware::history($this->history), 'history');

        $client = new Client(['handler' => $this->stack]);
        $app->instance(Client::class, $client);
        $app->instance('guzzle', $client);
    }

    /**
     * Return the underlying HandlerStack (for advanced test scenarios).
     */
    public function handlerStack(): HandlerStack
    {
        return $this->stack;
    }

    /**
     * Return the underlying MockHandler (for asserting exhaustion).
     */
    public function mockHandler(): MockHandler
    {
        return $this->mock;
    }
}
