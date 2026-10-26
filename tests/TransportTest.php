<?php

namespace Skrybe\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Skrybe\Exception\ApiException;
use Skrybe\Exception\AuthenticationException;
use Skrybe\Exception\ConnectionException;
use Skrybe\Exception\InvalidRequestException;
use Skrybe\Exception\RateLimitException;
use Skrybe\Exception\SkrybeException;
use Skrybe\Skrybe;
use Skrybe\Version;

class TransportTest extends TestCase
{
    public function testRequiresAnApiKey(): void
    {
        $this->expectException(InvalidRequestException::class);
        new Skrybe('');
    }

    public function testDefaultsToTheProductionApi(): void
    {
        $this->assertSame('https://dashboard.skry.be', Skrybe::DEFAULT_BASE_URL);
        $skrybe = $this->client(['7'], ['baseUrl' => Skrybe::DEFAULT_BASE_URL]);
        $skrybe->lists->activeSubscriberCount('x');
        $this->assertSame('https://dashboard.skry.be/api/subscribers/active-subscriber-count.php', (string) $this->request()->getUri());
    }

    public function testPostsAFormBodyWithTheApiKeyAndAUserAgent(): void
    {
        $skrybe = $this->client(['4211'], ['baseUrl' => self::BASE . '///']);

        $this->assertSame(4211, $skrybe->lists->activeSubscriberCount('AbC892763xy'));
        $request = $this->request();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::BASE . '/api/subscribers/active-subscriber-count.php', (string) $request->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('list_id=AbC892763xy&api_key=test-api-key', (string) $request->getBody());
        $this->assertSame('skrybe-sdk-php/' . Version::VERSION, $request->getHeaderLine('User-Agent'));
    }

    public function testTurnsAProseFailureCarriedByHttp200IntoATypedException(): void
    {
        $skrybe = $this->client(['Invalid API key']);
        try {
            $skrybe->lists->list();
            $this->fail('expected an exception');
        } catch (AuthenticationException $e) {
            $this->assertInstanceOf(SkrybeException::class, $e);
            $this->assertSame('authentication', $e->getType());
            $this->assertSame('invalid_api_key', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
            $this->assertNotEmpty($e->getHint());
        }
    }

    public static function proseCases(): array
    {
        return [
            ['List does not exist', ApiException::class, 'list_not_found'],
            ['List ID not passed', InvalidRequestException::class, 'list_id_invalid'],
            ['Quota exceeded. Please upgrade your plan.', RateLimitException::class, 'quota_exceeded'],
            ['Test send rate limit reached, try later', RateLimitException::class, 'rate_limited'],
            ['Unable to insert campaign', ApiException::class, 'operation_failed'],
            ['List already has a drip autoresponder', ApiException::class, 'drip_exists'],
            ['Domain "example.com" is not verified', ApiException::class, 'domain_not_verified'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('proseCases')]
    public function testClassifiesProseByKind(string $body, string $class, string $code): void
    {
        $skrybe = $this->client([$body]);
        try {
            $skrybe->lists->get('x');
            $this->fail('expected an exception');
        } catch (SkrybeException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function testReadsTheErrorOutOfAJsonEndpointsEnvelopeAndStatus(): void
    {
        $skrybe = $this->client([new Response(400, [], '{"ok":false,"error":"Invalid from_email"}')]);
        try {
            $skrybe->emails->send(['fromName' => 'a', 'fromEmail' => 'b', 'replyTo' => 'c', 'subject' => 'd', 'htmlText' => 'e', 'to' => ['x@y.z']]);
            $this->fail('expected an exception');
        } catch (InvalidRequestException $e) {
            $this->assertSame('Invalid from_email', $e->getMessage());
            $this->assertSame(400, $e->getStatus());
        }
    }

    public function testRetriesAReadOn503HonouringRetryAfter(): void
    {
        $skrybe = $this->client([new Response(503, ['Retry-After' => '0'], ''), '7']);
        $this->assertSame(7, $skrybe->lists->activeSubscriberCount('x'));
        $this->assertCount(2, $this->history);
    }

    public function testNeverRetriesAWrite(): void
    {
        $skrybe = $this->client([new Response(503, ['Retry-After' => '0'], ''), '{}']);
        $this->expectException(ApiException::class);
        try {
            $skrybe->campaigns->send(1, ['listIds' => ['a']]);
        } finally {
            $this->assertCount(1, $this->history);
        }
    }

    public function testRespectsMaxRetriesZero(): void
    {
        $skrybe = $this->client([new Response(503, ['Retry-After' => '0'], ''), '7'], ['maxRetries' => 0]);
        try {
            $skrybe->lists->activeSubscriberCount('x');
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame('server_error', $e->getErrorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testTimesOutAsAConnectionException(): void
    {
        $request = new Request('POST', self::BASE);
        $skrybe = $this->client([new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28])]);
        try {
            $skrybe->templates->delete(1);
            $this->fail('expected an exception');
        } catch (ConnectionException $e) {
            $this->assertSame('timeout', $e->getErrorCode());
        }
    }

    public function testReportsAnUnreachableHostAsAConnectionException(): void
    {
        $request = new Request('POST', self::BASE);
        $cause = new ConnectException('Could not resolve host', $request);
        $skrybe = $this->client([$cause], ['maxRetries' => 0]);
        try {
            $skrybe->brands->list();
            $this->fail('expected an exception');
        } catch (ConnectionException $e) {
            $this->assertSame('network_error', $e->getErrorCode());
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function testRetriesADroppedConnectionOnARead(): void
    {
        $request = new Request('POST', self::BASE);
        // Retry-After cannot shorten a connection-error backoff, so this waits ~0.5s.
        $skrybe = $this->client([new ConnectException('Connection reset', $request), '3'], ['maxRetries' => 1]);
        $this->assertSame(3, $skrybe->lists->activeSubscriberCount('x'));
        $this->assertCount(2, $this->history);
    }

    public function testVersionMatchesTheChangelog(): void
    {
        $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
        $this->assertMatchesRegularExpression('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog);
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m);
        $this->assertSame($m[1], Version::VERSION);
    }
}
