<?php

namespace Skrybe\Internal;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Skrybe\Exception\ApiException;
use Skrybe\Exception\ConnectionException;
use Skrybe\Exception\SkrybeException;
use Skrybe\Version;

/**
 * @internal
 */
final class Transport
{
    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    private readonly string $baseUrl;
    private LoggerInterface $logger;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly ClientInterface $http,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = new NullLogger();
    }

    /**
     * Build the Guzzle client. `http_errors` is off because a 4xx from the JSON
     * endpoints carries the message we want to report.
     *
     * @param array<string, mixed> $httpOptions extra Guzzle options, e.g. proxy or verify
     */
    public static function guzzle(float $timeout, ?callable $handler, array $httpOptions): Client
    {
        $config = array_merge($httpOptions, [
            'timeout' => $timeout,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        return new Client($config);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Perform a request and return the body as text.
     *
     * Legacy endpoints answer HTTP 200 with a prose error, so a 2xx here is not
     * proof of success: recognised prose errors are thrown, and anything else
     * is handed back for the caller to parse.
     *
     * @param array<string, string|int|null> $body form fields; nulls are left out
     * @param bool $retryable opt in to retrying. Off by default because most
     *     endpoints create or send something and none take an idempotency key:
     *     a 504 from a send that actually landed would send twice on retry.
     */
    public function text(string $path, array $body = [], bool $retryable = false): string
    {
        [$response, $text] = $this->send('POST', $path, $body, 'form', false, $retryable);
        if ($response->getStatusCode() >= 300) {
            throw $this->errorFromResponse($response, $text);
        }
        $prose = Errors::classifyProse($text, $response->getStatusCode());
        if ($prose !== null) {
            throw $prose;
        }
        return $text;
    }

    /**
     * Perform a request against one of the endpoints that returns real JSON.
     *
     * @param array<string, string|int|null> $body
     * @return array<mixed>
     */
    public function json(
        string $path,
        array $body = [],
        string $method = 'POST',
        string $encoding = 'form',
        bool $anonymous = false,
        bool $retryable = false,
    ): array {
        [$response, $text] = $this->send($method, $path, $body, $encoding, $anonymous, $retryable);
        if ($response->getStatusCode() >= 300) {
            throw $this->errorFromResponse($response, $text);
        }
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            throw new ApiException(
                'malformed_response',
                'The API returned a response that was not valid JSON.',
                null,
                $response->getStatusCode(),
                $text,
            );
        }
        return $parsed;
    }

    /**
     * @param array<string, string|int|null> $body
     * @return array{0: ResponseInterface, 1: string}
     */
    private function send(string $method, string $path, array $body, string $encoding, bool $anonymous, bool $retryable): array
    {
        $target = $this->baseUrl . '/' . ltrim($path, '/');

        $fields = [];
        foreach ($body as $key => $value) {
            if ($value !== null) {
                $fields[$key] = (string) $value;
            }
        }
        if (!$anonymous) {
            $fields['api_key'] = $this->apiKey;
        }

        $options = ['headers' => ['User-Agent' => 'skrybe-sdk-php/' . Version::VERSION]];
        if ($method === 'GET') {
            $options['query'] = $fields;
        } elseif ($encoding === 'json') {
            $options['json'] = $fields;
        } else {
            $options['form_params'] = $fields;
        }

        $attempts = $retryable ? $this->maxRetries + 1 : 1;
        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);
            try {
                $response = $this->http->request($method, $target, $options);
            } catch (ConnectException $e) {
                // Logged without the body: it carries the API key.
                $this->logger->warning('Skrybe API request failed', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);
                if (self::isTimeout($e)) {
                    throw new ConnectionException(
                        'timeout',
                        "Request to {$target} timed out after {$this->timeout}s.",
                        null,
                        null,
                        null,
                        $e,
                    );
                }
                $lastError = $e;
                if ($attempt < $attempts) {
                    usleep(self::backoffMs($attempt, null) * 1000);
                    continue;
                }
                break;
            } catch (GuzzleException $e) {
                throw new ConnectionException('network_error', "Could not reach {$this->baseUrl}: {$e->getMessage()}", null, null, null, $e);
            }

            $status = $response->getStatusCode();
            $this->logger->debug('Skrybe API request', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            if (in_array($status, self::RETRYABLE_STATUS, true) && $attempt < $attempts) {
                usleep(self::backoffMs($attempt, $response->getHeaderLine('Retry-After')) * 1000);
                continue;
            }
            return [$response, (string) $response->getBody()];
        }

        throw new ConnectionException(
            'network_error',
            "Could not reach {$this->baseUrl}: " . ($lastError?->getMessage() ?? 'unknown error'),
            null,
            null,
            null,
            $lastError,
        );
    }

    /**
     * Build an exception from a non-2xx response. The JSON endpoints use
     * `{ok:false,error:...}` (api/emails/send.php) or
     * `{status:"error",message:...}` (api/emails/send-transactional.php).
     */
    private function errorFromResponse(ResponseInterface $response, string $text): SkrybeException
    {
        $status = $response->getStatusCode();
        $message = trim($text);
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            $extracted = $parsed['error'] ?? $parsed['message'] ?? null;
            if (is_string($extracted) && $extracted !== '') {
                $message = $extracted;
            }
        }
        if ($message === '') {
            $message = "HTTP {$status} from the Skrybe API.";
        }
        return Errors::create(Errors::typeForStatus($status), Errors::codeForStatus($status), $message, null, $status, $text);
    }

    /** Honour Retry-After when present, else exponential backoff with jitter. */
    private static function backoffMs(int $attempt, ?string $retryAfter): int
    {
        if ($retryAfter !== null && $retryAfter !== '' && ctype_digit(trim($retryAfter))) {
            return min((int) trim($retryAfter) * 1000, 30_000);
        }
        return 2 ** ($attempt - 1) * 500 + random_int(0, 249);
    }

    private static function isTimeout(ConnectException $e): bool
    {
        // cURL error 28 is CURLE_OPERATION_TIMEDOUT; the stream handler only says so in prose.
        return ($e->getHandlerContext()['errno'] ?? null) === 28
            || stripos($e->getMessage(), 'timed out') !== false;
    }
}
