<?php

namespace Skrybe\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;
use Skrybe\Skrybe;

abstract class TestCase extends BaseTestCase
{
    protected const KEY = 'test-api-key';
    protected const BASE = 'https://skrybe.test';

    protected MockHandler $mock;
    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /**
     * A client whose requests go to a MockHandler, which throws on an unqueued
     * request, so no test can reach the real API.
     *
     * @param list<Response|\Throwable|string> $replies a string is a 200 with that body
     */
    protected function client(array $replies = [], array $options = []): Skrybe
    {
        $this->mock = new MockHandler(array_map(
            fn ($r) => is_string($r) ? new Response(200, [], $r) : $r,
            $replies,
        ));
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new Skrybe(self::KEY, $options + ['baseUrl' => self::BASE, 'handler' => $stack]);
    }

    protected function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /** The path of a request, relative to the base URL. */
    protected function path(int $index = 0): string
    {
        return $this->request($index)->getUri()->getPath();
    }

    /** The form fields of the only request, less the api key every request carries. */
    protected function sent(): array
    {
        $this->assertCount(1, $this->history);
        parse_str((string) $this->request()->getBody(), $fields);
        $this->assertSame(self::KEY, $fields['api_key'] ?? null);
        unset($fields['api_key']);
        return $fields;
    }

    /**
     * Build a body the way get-lists.php / get-brands.php do on older installs:
     * by string concatenation, with names not escaped.
     *
     * @param list<array{id: string, name: string}> $rows
     */
    protected static function phpNumberedPayload(string $prefix, array $rows): string
    {
        $out = '';
        foreach ($rows as $i => $row) {
            $out .= '"' . $prefix . ($i + 1) . '": { "id": "' . $row['id'] . '", "name": "' . $row['name'] . '" },';
        }
        return '{' . rtrim($out, ',') . '}';
    }
}
