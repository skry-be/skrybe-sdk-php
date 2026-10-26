<?php

namespace Skrybe\Exception;

use Exception;
use Throwable;

/**
 * Every failure the SDK reports. The Skrybe API answers most failures with
 * HTTP 200 and a prose sentence; this turns them into something code can
 * branch on: a stable `getErrorCode()` slug, and a subclass saying what kind
 * of failure it is.
 *
 * - AuthenticationException: the API key is missing or wrong.
 * - InvalidRequestException: the request itself is wrong; retrying it unchanged will fail again.
 * - RateLimitException: a quota or rate limit was hit.
 * - ConnectionException: the API could not be reached, or did not answer in time.
 * - ApiException: anything else the API refused or got wrong.
 */
class SkrybeException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly ?string $hint = null,
        private readonly ?int $status = null,
        private readonly ?string $raw = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** Stable machine-readable slug, e.g. `list_not_found`. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** authentication, invalid_request, rate_limit, connection or api. */
    public function getType(): string
    {
        return 'api';
    }

    /** What to do about it, when we know. */
    public function getHint(): ?string
    {
        return $this->hint;
    }

    /** HTTP status of the response, when there was one. */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** Raw response body, kept for debugging odd endpoints. */
    public function getRaw(): ?string
    {
        return $this->raw;
    }
}
