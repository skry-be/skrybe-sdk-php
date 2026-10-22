<?php

namespace Skrybe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Skrybe\Exception\SkrybeException;
use Skrybe\Exception\ValidationException;
use Skrybe\LoggerTrait;

class SkrybeSDK
{
    use LoggerTrait;

    private string $apiKey;
    private Client $client;
    private const DEFAULT_BASE_URL = "https://dashboard.skry.be";
    private $lastRequestTime = 0;
    private const MIN_REQUEST_INTERVAL = 0.1; // 100ms between requests

    /**
     * @param callable|null $handler Optional Guzzle handler (e.g. a HandlerStack
     *                               wrapping a MockHandler in tests).
     */
    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL, ?callable $handler = null)
    {
        $this->apiKey = $apiKey;
        $config = [
            'base_uri' => $baseUrl,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'SkrybeSDK-PHP/1.1'
            ]
        ];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->client = new Client($config);
    }

    private function createFormData(array $data): array
    {
        $formData = ['api_key' => $this->apiKey];

        foreach ($data as $key => $value) {
            if (!is_null($value)) {
                if (is_array($value) || is_object($value)) {
                    $formData[$key] = json_encode($value);
                } else {
                    $formData[$key] = $value;
                }
            }
        }

        return $formData;
    }

    private function handleRateLimit(): void
    {
        $now = microtime(true);
        $timeSinceLastRequest = $now - $this->lastRequestTime;
        
        if ($timeSinceLastRequest < self::MIN_REQUEST_INTERVAL) {
            usleep((int) ((self::MIN_REQUEST_INTERVAL - $timeSinceLastRequest) * 1000000));
        }
        
        $this->lastRequestTime = microtime(true);
    }

    private function makeRequest(string $endpoint, array $data)
    {
        $this->handleRateLimit();

        try {
            $this->getLogger()->info("Making request to {$endpoint}", [
                'endpoint' => $endpoint,
                'data' => $data
            ]);

            $response = $this->client->post($endpoint, [
                'form_params' => $this->createFormData($data)
            ]);

            $body = (string) $response->getBody();
            $result = json_decode($body, true);

            $this->getLogger()->debug("Response received", [
                'endpoint' => $endpoint,
                'response' => $result
            ]);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $body;
            }

            return $result;
        } catch (RequestException $e) {
            $this->getLogger()->error("Request failed", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw new SkrybeException(
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function makeJsonRequest(string $endpoint, array $data)
    {
        $this->handleRateLimit();

        $payload = array_merge(['api_key' => $this->apiKey], $data);
        $payload = array_filter($payload, fn($v) => !is_null($v));

        try {
            $this->getLogger()->info("Making JSON request to {$endpoint}", [
                'endpoint' => $endpoint,
                'data' => $payload
            ]);

            $response = $this->client->post($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload
            ]);

            $body = (string) $response->getBody();
            $result = json_decode($body, true);

            $this->getLogger()->debug("Response received", [
                'endpoint' => $endpoint,
                'response' => $result
            ]);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $body;
            }

            return $result;
        } catch (RequestException $e) {
            $this->getLogger()->error("Request failed", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw new SkrybeException(
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function validateEmailOptions(array $options): void
    {
        $required = ['fromName', 'fromEmail', 'subject', 'htmlText'];
        $errors = [];

        foreach ($required as $field) {
            if (!isset($options[$field]) || empty($options[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        if (isset($options['fromEmail']) && !filter_var($options['fromEmail'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['fromEmail' => 'Invalid email format']);
        }

        if (isset($options['to'])) {
            foreach ($options['to'] as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationException(['to' => "Invalid email format: $email"]);
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    private function validateCampaignOptions(array $options): void
    {
        $required = ['fromName', 'fromEmail', 'title', 'subject', 'htmlText'];
        $errors = [];

        foreach ($required as $field) {
            if (!isset($options[$field]) || empty($options[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        if (!filter_var($options['fromEmail'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['fromEmail' => 'Invalid email format']);
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    private function validateTransactionalEmailOptions(array $options): void
    {
        $errors = [];

        if (!isset($options['toEmail']) || empty($options['toEmail'])) {
            $errors[] = "Field 'toEmail' is required";
        } elseif (!filter_var($options['toEmail'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Field 'toEmail' has invalid email format";
        }

        if (!isset($options['subject']) || empty($options['subject'])) {
            $errors[] = "Field 'subject' is required";
        }

        $hasHtmlBody = isset($options['htmlBody']) && !empty($options['htmlBody']);
        $hasPlainBody = isset($options['plainBody']) && !empty($options['plainBody']);

        if (!$hasHtmlBody && !$hasPlainBody) {
            $errors[] = "Either 'htmlBody' or 'plainBody' is required";
        }

        if (isset($options['fromEmail']) && !empty($options['fromEmail'])) {
            if (!filter_var($options['fromEmail'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Field 'fromEmail' has invalid email format";
            }
        }

        if (isset($options['replyTo']) && !empty($options['replyTo'])) {
            if (!filter_var($options['replyTo'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Field 'replyTo' has invalid email format";
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    public function sendEmail(array $options)
    {
        $this->validateEmailOptions($options);

        return $this->makeRequest('/api/emails/send.php', [
            'from_name' => $options['fromName'],
            'from_email' => $options['fromEmail'],
            'reply_to' => $options['replyTo'],
            'subject' => $options['subject'],
            'html_text' => $options['htmlText'],
            'plain_text' => $options['plainText'] ?? null,
            'to' => $options['to'] ?? null,
            'recipient-variables' => $options['recipientVariables'] ?? null,
            'list_ids' => isset($options['listIds']) ? implode(',', $options['listIds']) : null,
            'query_string' => $options['queryString'] ?? null,
            'track_opens' => $options['trackOpens'] ?? null,
            'track_clicks' => $options['trackClicks'] ?? null,
            'schedule_date_time' => $options['scheduleDateTime'] ?? null,
            'schedule_timezone' => $options['scheduleTimezone'] ?? null,
        ]);
    }

    /**
     * Send a transactional email (OTP, password reset, alerts, etc.)
     *
     * Unlike sendEmail(), this method delivers immediately without queuing or scheduling.
     *
     * @param array $options {
     *     @type string $toEmail     Required. The recipient email address (single email only).
     *     @type string $subject     Required. The email subject.
     *     @type string $htmlBody    The HTML version of your email. Required unless plainBody is provided.
     *     @type string $plainBody   The plain text version of your email. Required if htmlBody is omitted.
     *     @type string $fromName    Optional. Override of the brand's 'From name'.
     *     @type string $fromEmail   Optional. Override of the brand's 'From email'. Must be on an allowed domain.
     *     @type string $replyTo     Optional. Override of the brand's 'Reply to' email.
     * }
     * @return array The API response
     * @throws ValidationException If required parameters are missing or invalid
     * @throws SkrybeException If the API request fails
     */
    public function sendTransactionalEmail(array $options)
    {
        $this->validateTransactionalEmailOptions($options);

        return $this->makeJsonRequest('/api/emails/send-transactional.php', [
            'to_email' => $options['toEmail'],
            'subject' => $options['subject'],
            'html_body' => $options['htmlBody'] ?? null,
            'plain_body' => $options['plainBody'] ?? null,
            'from_name' => $options['fromName'] ?? null,
            'from_email' => $options['fromEmail'] ?? null,
            'reply_to' => $options['replyTo'] ?? null,
        ]);
    }

    public function createCampaign(array $options)
    {
        $this->validateCampaignOptions($options);

        return $this->makeRequest('/api/campaigns/create.php', [
            'from_name' => $options['fromName'],
            'from_email' => $options['fromEmail'],
            'reply_to' => $options['replyTo'],
            'title' => $options['title'],
            'subject' => $options['subject'],
            'html_text' => $options['htmlText'],
            'plain_text' => $options['plainText'] ?? null,
            'list_ids' => $options['listIds'] ?? null,
            'segment_ids' => $options['segmentIds'] ?? null,
            'exclude_list_ids' => $options['excludeListIds'] ?? null,
            'exclude_segments_ids' => $options['excludeSegmentIds'] ?? null,
            'query_string' => $options['queryString'] ?? null,
            'track_opens' => $options['trackOpens'] ?? null,
            'track_clicks' => $options['trackClicks'] ?? null,
            'send_campaign' => $options['sendCampaign'] ?? null,
            'schedule_date_time' => $options['scheduleDateTime'] ?? null,
            'schedule_timezone' => $options['scheduleTimezone'] ?? null,
        ]);
    }

    public function getLists(bool $includeHidden = false): array
    {
        return $this->makeRequest('/api/lists/get-lists.php', [
            'include_hidden' => $includeHidden ? 'yes' : 'no',
        ]);
    }

    public function getCampaigns(array $options = []): array
    {
        return $this->makeRequest('/api/campaigns/get-campaigns.php', [
            'page' => $options['page'] ?? 1,
            'limit' => $options['limit'] ?? 10,
            'status' => $options['status'] ?? null,
        ]);
    }

    public function getSubscribers(string $listId, array $options = []): array
    {
        return $this->makeRequest('/api/subscribers/get-subscribers.php', [
            'list_id' => $listId,
            'page' => $options['page'] ?? 1,
            'limit' => $options['limit'] ?? 10,
            'status' => $options['status'] ?? null,
        ]);
    }

    public function addSubscriber(string $listId, array $subscriberData): array
    {
        return $this->makeRequest('/api/subscribers/add.php', array_merge(
            ['list_id' => $listId],
            $subscriberData
        ));
    }
}
