<?php

namespace Skrybe;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Skrybe\Exception\InvalidRequestException;
use Skrybe\Internal\Transport;
use Skrybe\Resource\Autoresponders;
use Skrybe\Resource\Brands;
use Skrybe\Resource\Campaigns;
use Skrybe\Resource\Emails;
use Skrybe\Resource\Lists;
use Skrybe\Resource\Stats;
use Skrybe\Resource\Subscribers;
use Skrybe\Resource\Templates;

/**
 * The Skrybe API client.
 *
 *     $skrybe = new Skrybe('your-api-key');
 *     $lists = $skrybe->lists->list();
 */
final class Skrybe implements LoggerAwareInterface
{
    public const DEFAULT_BASE_URL = 'https://dashboard.skry.be';

    public readonly Emails $emails;
    public readonly Campaigns $campaigns;
    public readonly Lists $lists;
    public readonly Subscribers $subscribers;
    public readonly Templates $templates;
    public readonly Autoresponders $autoresponders;
    public readonly Brands $brands;
    public readonly Stats $stats;

    private readonly Transport $transport;

    /**
     * @param string $apiKey a brand's API key, from Settings in the Skrybe dashboard. Keep it server-side.
     * @param array{
     *     baseUrl?: string,
     *     timeout?: float,
     *     maxRetries?: int,
     *     handler?: callable,
     *     httpOptions?: array<string, mixed>,
     *     logger?: LoggerInterface,
     * } $options
     *     - baseUrl: defaults to https://dashboard.skry.be.
     *     - timeout: seconds per attempt, default 30.
     *     - maxRetries: extra attempts for reads after a dropped connection or
     *       a 429/502/503/504, default 2. Writes are never retried: the API has
     *       no idempotency keys, so a retried send could go out twice.
     *     - handler: a Guzzle handler, e.g. a HandlerStack around a MockHandler in tests.
     *     - httpOptions: extra Guzzle request options, e.g. `proxy`, or `verify`
     *       pointing at a corporate CA bundle.
     *     - logger: PSR-3 logger for request method, path, status and timing.
     *       Bodies are never logged, since they carry the API key.
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new InvalidRequestException('api_key_missing', 'An API key is required.', 'Generate one in the Skrybe dashboard under Settings.');
        }

        $timeout = (float) ($options['timeout'] ?? 30);
        $this->transport = new Transport(
            $apiKey,
            $options['baseUrl'] ?? self::DEFAULT_BASE_URL,
            $timeout,
            max(0, (int) ($options['maxRetries'] ?? 2)),
            Transport::guzzle($timeout, $options['handler'] ?? null, $options['httpOptions'] ?? []),
        );
        if (isset($options['logger'])) {
            $this->transport->setLogger($options['logger']);
        }

        $this->emails = new Emails($this->transport);
        $this->campaigns = new Campaigns($this->transport);
        $this->lists = new Lists($this->transport);
        $this->subscribers = new Subscribers($this->transport);
        $this->templates = new Templates($this->transport);
        $this->autoresponders = new Autoresponders($this->transport);
        $this->brands = new Brands($this->transport);
        $this->stats = new Stats($this->transport);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->transport->setLogger($logger);
    }
}
