<?php

namespace Skrybe\Internal;

use Skrybe\Exception\ApiException;
use Skrybe\Exception\AuthenticationException;
use Skrybe\Exception\ConnectionException;
use Skrybe\Exception\InvalidRequestException;
use Skrybe\Exception\RateLimitException;
use Skrybe\Exception\SkrybeException;
use Throwable;

/**
 * Turns the API's prose answers into typed exceptions.
 *
 * Only api/emails/send.php, api/emails/send-transactional.php and
 * api/stats/emails-sent.php use real status codes; everything else answers
 * HTTP 200 with a sentence. Kept in step with @skrybe/sdk's src/errors.ts and
 * skrybe-cli's src/api/errors.ts.
 *
 * @internal
 */
final class Errors
{
    private const LIST_IDS_HINT = 'Call $skrybe->lists->list() to see the IDs available to this API key.';
    private const UNDER_REVIEW_HINT = 'Sending is paused while the brand is reviewed for a high bounce rate. Contact Skrybe support.';
    private const DOMAIN_HINT = 'Verify the From domain for this brand in the Skrybe dashboard.';

    /**
     * Prose returned by the legacy endpoints, keyed by the lowercased trimmed
     * body: [code, type, hint].
     *
     * @var array<string, array{0: string, 1: string, 2?: string}>
     */
    private const PROSE = [
        // Authentication
        'api key not passed' => ['api_key_missing', 'authentication'],
        'invalid api key' => ['invalid_api_key', 'authentication', 'Generate a key in the Skrybe dashboard under Settings. Keys are cleared for every login of a brand placed under review.'],

        // Request shape
        'no data passed' => ['no_data_passed', 'invalid_request'],
        'some fields are missing.' => ['missing_fields', 'invalid_request'],

        // Brands and lists
        'brand id not passed' => ['brand_id_missing', 'invalid_request'],
        // Also the answer to the install's main account key, which no brand is tied to.
        'brand does not exist' => ['brand_not_found', 'api', 'Use an API key from a brand login; the main account key is not tied to a brand.'],
        'list does not exist' => ['list_not_found', 'api', self::LIST_IDS_HINT],
        'invalid list id.' => ['list_not_found', 'api', self::LIST_IDS_HINT],
        // Also what the server produces from an id it cannot decrypt.
        'list id not passed' => ['list_id_invalid', 'invalid_request', 'A list ID is the encrypted value lists->list() returns, not the number in the dashboard URL.'],

        // Autoresponders
        'autoresponder id not passed' => ['autoresponder_id_missing', 'invalid_request'],
        'autoresponder does not exist' => ['autoresponder_not_found', 'api'],
        'email id not passed' => ['email_id_missing', 'invalid_request'],
        'autoresponder email does not exist' => ['autoresponder_email_not_found', 'api'],
        'autoresponder name not passed' => ['autoresponder_name_missing', 'invalid_request'],
        'type must be drip, annually or on_date' => ['invalid_autoresponder_type', 'invalid_request'],
        'list already has a drip autoresponder' => ['drip_exists', 'api', 'A list can have one drip autoresponder. Add emails to the existing one.'],
        'custom_field not passed' => ['custom_field_missing', 'invalid_request', 'annually and on_date autoresponders send on a Date custom field of the list.'],
        'field is not a date field' => ['field_not_date', 'invalid_request'],
        'field already has an autoresponder' => ['field_has_autoresponder', 'api'],
        'subject not passed' => ['subject_missing', 'invalid_request'],
        'a drip email cannot go before signup' => ['invalid_when', 'invalid_request'],
        'when must be immediately, or a delay such as +3 days' => ['invalid_when', 'invalid_request'],
        'when must be on, or an offset such as +9 hours or -1 days' => ['invalid_when', 'invalid_request'],

        // Templates
        'template id not passed' => ['template_id_missing', 'invalid_request'],
        'template does not exist' => ['template_not_found', 'api'],
        'template name not passed' => ['template_name_missing', 'invalid_request'],
        'template name cannot be empty' => ['template_name_missing', 'invalid_request'],
        'html not passed' => ['html_missing', 'invalid_request'],
        'editor must be html or dragdrop' => ['invalid_editor', 'invalid_request'],

        // List management
        'list name not passed' => ['list_name_missing', 'invalid_request'],
        'list name is too long' => ['list_name_too_long', 'invalid_request', 'A list name can be at most 100 characters.'],
        'opt_in must be single or double' => ['invalid_opt_in', 'invalid_request'],
        'nothing to update' => ['nothing_to_update', 'invalid_request', 'Pass at least one field to change.'],
        'list is used by a scheduled or sending campaign' => ['list_in_use', 'api', 'Unschedule the campaign, or wait for it to finish sending, then delete the list.'],

        // Custom fields
        'field name not passed' => ['field_name_missing', 'invalid_request'],
        'invalid field name' => ['invalid_field_name', 'invalid_request', 'Up to 100 characters, without : % [ ] , " < or >.'],
        'name and email are built-in fields' => ['reserved_field_name', 'invalid_request'],
        'type must be text or date' => ['invalid_field_type', 'invalid_request'],
        'field already exists' => ['field_exists', 'api', 'Field names are case-insensitive.'],
        'field does not exist' => ['field_not_found', 'api'],
        'field is used by an autoresponder' => ['field_in_use', 'api', 'Change the autoresponder in the Skrybe dashboard first.'],
        'field is used by a segment' => ['field_in_use', 'api', 'Remove it from the segment in the Skrybe dashboard first.'],
        'list was changed by another request. try again.' => ['conflict', 'api'],

        // Campaigns
        'campaign id not passed' => ['campaign_id_missing', 'invalid_request'],
        'campaign does not exist' => ['campaign_not_found', 'api'],
        'invalid status' => ['invalid_status', 'invalid_request'],
        'invalid page' => ['invalid_page', 'invalid_request'],
        'invalid limit' => ['invalid_limit', 'invalid_request'],
        'invalid type' => ['invalid_type', 'invalid_request'],

        // Sending a campaign
        'list or segment id(s) not passed' => ['recipients_missing', 'invalid_request', 'Pass at least one list or segment ID.'],
        'one or more list ids are invalid' => ['list_not_found', 'api', self::LIST_IDS_HINT],
        'one or more segment ids are invalid' => ['segment_not_found', 'api'],
        'campaign has already been sent' => ['campaign_not_draft', 'api', 'It has already started sending, so it can no longer be sent, scheduled or edited.'],
        'campaign is scheduled' => ['campaign_scheduled', 'api', 'Unschedule it first, or leave it to send at its scheduled time.'],
        'campaign is not scheduled' => ['campaign_not_scheduled', 'api'],

        // Editing and deleting campaigns
        'campaign is sending' => ['campaign_sending', 'api', 'Stop it in the Skrybe dashboard first, or wait for it to finish.'],
        'subject cannot be empty' => ['subject_empty', 'invalid_request'],
        'html cannot be empty' => ['html_empty', 'invalid_request'],
        'invalid from_email' => ['invalid_from_email', 'invalid_request'],
        'invalid reply_to' => ['invalid_reply_to', 'invalid_request'],
        'track_opens and track_clicks must be 0, 1 or 2' => ['invalid_tracking', 'invalid_request', '0 off, 1 on, 2 anonymous.'],
        'campaign was changed by another request. try again.' => ['conflict', 'api'],

        // Scheduling
        'schedule_date_time not passed' => ['schedule_missing', 'invalid_request'],
        'schedule_date_time is invalid' => ['invalid_schedule', 'invalid_request', 'Use a date and time such as "2027-06-15 18:05" or "June 15, 2027 6:05pm".'],
        'schedule_date_time is in the past' => ['schedule_in_past', 'invalid_request', "The time is read in the timezone given, or the account's timezone if none is."],
        'schedule_timezone is invalid' => ['invalid_timezone', 'invalid_request', 'Use an IANA name such as Africa/Lagos or America/New_York.'],

        // Test sends
        'email addresses not passed' => ['emails_missing', 'invalid_request'],
        'too many email addresses' => ['too_many_emails', 'invalid_request', 'A test send goes to at most 5 addresses.'],
        'quota exceeded. please upgrade your plan.' => ['quota_exceeded', 'rate_limit'],
        'no active subscribers to send to' => ['no_recipients', 'api', 'Every subscriber on those lists is unsubscribed, bounced, unconfirmed or excluded.'],

        // Subscribers
        'subscriber does not exist' => ['subscriber_not_found', 'api'],
        'email does not exist in list' => ['subscriber_not_found', 'api'],
        'email not passed' => ['email_missing', 'invalid_request'],
        'email address not passed' => ['email_missing', 'invalid_request'],
        'invalid email address.' => ['invalid_email', 'invalid_request'],
        'bounced email address.' => ['bounced_email', 'api'],
        'email is suppressed.' => ['suppressed_email', 'api'],
        // unsubscribe.php's wording for an address that is not on the list.
        'email does not exist.' => ['subscriber_not_found', 'api'],
        'ip address is invalid.' => ['invalid_ip_address', 'invalid_request'],
        'country must be a valid 2 letter country code' => ['invalid_country', 'invalid_request', 'Use a two-letter ISO 3166-1 code, e.g. NG.'],
        'referrer is not a valid url' => ['invalid_referrer', 'invalid_request'],
        'consent not given.' => ['consent_not_given', 'invalid_request', "The list requires GDPR consent; pass 'gdpr' => true."],
        'failed recaptcha test.' => ['recaptcha_failed', 'api', 'The list has reCAPTCHA enabled on its subscribe form, which an API call cannot satisfy.'],

        // SMS
        'phone number not passed' => ['phone_missing', 'invalid_request'],
        'message not passed' => ['message_missing', 'invalid_request'],
        'invalid phone number' => ['invalid_phone', 'invalid_request', 'Use a Nigerian mobile number, e.g. 0803 123 4567 or +2348031234567.'],
        'use a brand api key' => ['brand_key_required', 'invalid_request', 'SMS is charged to a brand. Use an API key from a brand login, not the main account key.'],
        'sms is not enabled for this brand' => ['sms_not_enabled', 'api', "An admin sets the brand's SMS allowance under brand settings, SMS."],
        'monthly sms allowance exceeded' => ['quota_exceeded', 'rate_limit', 'The allowance resets on the 1st of the month, Lagos time.'],
        'message id not passed' => ['message_id_missing', 'invalid_request'],
        'message does not exist' => ['message_not_found', 'api'],

        // Sending
        'unauthorised from email domain' => ['unauthorised_from_domain', 'api', 'The From domain must be verified for this brand, or listed in its allowed domains.'],
    ];

    /**
     * Prose with a variable part, matched by pattern after the exact table.
     *
     * @var array<string, array{0: string, 1: string, 2?: string}>
     */
    private const PROSE_PATTERNS = [
        '/^error: this campaign would exceed/' => ['quota_exceeded', 'rate_limit'],
        '/^account under review:/' => ['account_under_review', 'api', self::UNDER_REVIEW_HINT],
        '/^brand is under review/' => ['account_under_review', 'api', self::UNDER_REVIEW_HINT],
        '/^a field is too long/' => ['field_too_long', 'invalid_request'],
        '/^message is longer than \d+ sms segments/' => ['message_too_long', 'invalid_request', 'A segment is 160 characters, or 70 with emoji or special characters.'],
        '/^test send rate limit reached/' => ['rate_limited', 'rate_limit', 'Test sends are limited to 20 an hour per brand, shared with the Skrybe dashboard.'],
        '/^domain not verified in ses/' => ['domain_not_verified', 'api', self::DOMAIN_HINT],
        '/^domain ".*" is not verified/' => ['domain_not_verified', 'api', self::DOMAIN_HINT],
    ];

    /** Bodies that mean "nothing to return": these endpoints answer with prose when the brand has no rows. */
    private const PROSE_EMPTY = ['no lists found', 'no brands found', 'no campaigns found'];

    /** Bodies that mean success even though they are bare prose. */
    private const PROSE_OK = ['1', 'true', 'already subscribed.'];

    public static function create(
        string $type,
        string $code,
        string $message,
        ?string $hint = null,
        ?int $status = null,
        ?string $raw = null,
        ?Throwable $previous = null,
    ): SkrybeException {
        return match ($type) {
            'authentication' => new AuthenticationException($code, $message, $hint, $status, $raw, $previous),
            'invalid_request' => new InvalidRequestException($code, $message, $hint, $status, $raw, $previous),
            'rate_limit' => new RateLimitException($code, $message, $hint, $status, $raw, $previous),
            'connection' => new ConnectionException($code, $message, $hint, $status, $raw, $previous),
            default => new ApiException($code, $message, $hint, $status, $raw, $previous),
        };
    }

    /**
     * Classify a legacy plain-text body. Null when the body is not a
     * recognised error, which callers should treat as data.
     */
    public static function classifyProse(string $body, ?int $status = null): ?SkrybeException
    {
        $trimmed = trim($body);
        $key = strtolower($trimmed);

        $rule = self::PROSE[$key] ?? null;
        if ($rule === null) {
            foreach (self::PROSE_PATTERNS as $pattern => $candidate) {
                if (preg_match($pattern, $key) === 1) {
                    $rule = $candidate;
                    break;
                }
            }
        }
        if ($rule !== null) {
            return self::create($rule[1], $rule[0], $trimmed, $rule[2] ?? null, $status, $body);
        }

        // A family of "Unable to ..." server-side failures not worth enumerating.
        if (str_starts_with($key, 'unable to')) {
            return new ApiException('operation_failed', $trimmed, null, $status, $body);
        }

        return null;
    }

    public static function isProseEmpty(string $body): bool
    {
        return in_array(strtolower(trim($body)), self::PROSE_EMPTY, true);
    }

    public static function isProseSuccess(string $body): bool
    {
        return in_array(strtolower(trim($body)), self::PROSE_OK, true);
    }

    /** The exception type for an HTTP status from one of the JSON endpoints. */
    public static function typeForStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'authentication',
            $status === 402, $status === 429 => 'rate_limit',
            $status === 400, $status === 405, $status === 422 => 'invalid_request',
            default => 'api',
        };
    }

    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request',
            401 => 'unauthenticated',
            402 => 'quota_exceeded',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            429 => 'rate_limited',
            default => $status >= 500 ? 'server_error' : 'api_error',
        };
    }

    /**
     * Render an unexpected response body for a message: whitespace collapsed so
     * a multi-line PHP warning stays on one line, and truncated so a stray HTML
     * page does not swamp a log. The whole body stays on getRaw().
     */
    public static function excerpt(string $body, int $limit = 200): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        if ($flat === '') {
            return '(an empty response)';
        }
        // Count characters, not bytes, without depending on mbstring.
        if (preg_match('/^.{' . $limit . '}/us', $flat, $m) === 1 && strlen($m[0]) < strlen($flat)) {
            return $m[0] . '…';
        }
        return $flat;
    }

    /** A body that is neither a known success nor a known failure. */
    public static function unexpectedResponse(string $operation, string $body): ApiException
    {
        return new ApiException('unexpected_response', "Unexpected response from {$operation}: " . self::excerpt($body), null, null, $body);
    }

    /** A required parameter left out, caught before anything is sent. */
    public static function requireParams(array $params, array $names): void
    {
        $missing = array_values(array_filter($names, fn (string $n) => !isset($params[$n]) || $params[$n] === ''));
        if ($missing !== []) {
            throw new InvalidRequestException(
                'missing_params',
                'Missing required parameter' . (count($missing) > 1 ? 's' : '') . ': ' . implode(', ', $missing) . '.',
            );
        }
    }
}
