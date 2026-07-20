<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;

class ScriptedGoogleTransportTest extends TestCase
{
    /**
     * Scripted responses are returned in the order they were queued.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function scriptedResponsesReturnedInOrder(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, ['name' => 'first'])
            ->respondJson(200, ['name' => 'second'])
            ->respondJson(201, ['name' => 'third']);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        $r1 = $client->request('GET', 'https://example.com/api/v1/first');
        $r2 = $client->request('GET', 'https://example.com/api/v1/second');
        $r3 = $client->request('POST', 'https://example.com/api/v1/third');

        $this->assertEquals(200, $r1->getStatusCode());
        $this->assertEquals(['name' => 'first'], json_decode($r1->getBody()->getContents(), true));

        $this->assertEquals(200, $r2->getStatusCode());
        $this->assertEquals(['name' => 'second'], json_decode($r2->getBody()->getContents(), true));

        $this->assertEquals(201, $r3->getStatusCode());
        $this->assertEquals(['name' => 'third'], json_decode($r3->getBody()->getContents(), true));
    }

    /**
     * History middleware captures the request URI for each call.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function historyCapturesRequestUri(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, ['ok' => true])
            ->respondJson(200, ['ok' => true]);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        $client->request('GET', 'https://health.googleapis.com/v1/measurements?page_size=50');
        $client->request('GET', 'https://health.googleapis.com/v1/spans?filter=type=steps');

        $this->assertEquals(2, $transport->requestCount());

        $requests = $transport->requests();
        $this->assertEquals('GET', $requests[0]['method']);
        // uri includes the full path with query string
        $this->assertEquals('https://health.googleapis.com/v1/measurements?page_size=50', $requests[0]['uri']);
        $this->assertEquals('page_size=50', $requests[0]['query']);

        $this->assertEquals('GET', $requests[1]['method']);
        $this->assertEquals('https://health.googleapis.com/v1/spans?filter=type=steps', $requests[1]['uri']);
        $this->assertEquals('filter=type=steps', $requests[1]['query']);
    }

    /**
     * lastUri() returns the URI of the most recent request.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function lastUriReturnsMostRecent(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, [])
            ->respondJson(200, [])
            ->respondJson(200, []);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        $client->request('GET', 'https://example.com/first');
        $client->request('GET', 'https://example.com/second');
        $client->request('GET', 'https://example.com/third');

        $this->assertEquals('https://example.com/third', $transport->lastUri());
    }

    /**
     * lastUri() returns null before any requests.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function lastUriIsNullBeforeAnyRequests(): void
    {
        $transport = ScriptedGoogleTransport::make();
        $this->assertNull($transport->lastUri());
    }

    /**
     * bind() makes an injected Guzzle client use the mock handler.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function bindMakesInjectedGuzzleUseMock(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, ['injected' => true]);

        $transport->bind($this->app);

        // Resolve via both binding keys
        $clientA = $this->app->make(Client::class);
        $clientB = $this->app->make('guzzle');

        $this->assertSame($clientA, $clientB, 'Both binding keys resolve the same instance');

        $response = $clientA->request('GET', 'https://example.com/test');
        $this->assertEquals(['injected' => true], json_decode($response->getBody()->getContents(), true));
    }

    /**
     * An unscripted extra request fails loudly rather than hanging or returning a real response.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function unscriptedExtraRequestFailsLoudly(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, ['ok' => true]);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        // First request is scripted — succeeds
        $client->request('GET', 'https://example.com/ok');

        // Second request has no scripted response — throws
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Mock queue is empty');
        $client->request('GET', 'https://example.com/unscripted');
    }

    /**
     * respondStatus queues a response with no body.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function respondStatusReturnsEmptyBody(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondStatus(429);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        $response = $client->request('GET', 'https://example.com/rate-limited', [
            'http_errors' => false,
        ]);
        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals('', $response->getBody()->getContents());
    }

    /**
     * Headers are captured in the request history.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function requestsCapturesHeaders(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, []);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        $client->request('GET', 'https://example.com/test', [
            'headers' => [
                'Authorization' => 'Bearer test-token',
                'X-Custom-Header' => 'test-value',
            ],
        ]);

        $requests = $transport->requests();
        $this->assertEquals(['Bearer test-token'], $requests[0]['headers']['Authorization']);
        $this->assertEquals(['test-value'], $requests[0]['headers']['X-Custom-Header']);
    }

    /**
     * respondJson accepts a pre-encoded string body.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function respondJsonAcceptsStringBody(): void
    {
        $rawBody = '{"custom":"response","parsed":false}';
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, $rawBody);

        $transport->bind($this->app);
        $client = $this->app->make(Client::class);

        $response = $client->request('GET', 'https://example.com/test');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($rawBody, $response->getBody()->getContents());
    }
}
