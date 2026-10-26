# skrybe/sdk-php

The official PHP SDK for the [Skrybe](https://skry.be) email marketing API.
It covers every brand-key endpoint: transactional and bulk email, the full
campaign lifecycle, lists and custom fields, subscribers, templates and
autoresponders.

Methods return parsed arrays and throw typed exceptions. The API answers most
failures with HTTP 200 and a sentence of prose, and the SDK turns those into
exceptions with stable codes, so you can branch on them.

## Requirements

- PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or newer within those branches (the
  2026-10-21 security releases that fix CVE-2026-109618 and CVE-2026-109619)
- Guzzle 7 (installed with the SDK)

## Install

```bash
composer require skrybe/sdk-php
```

## Quick start

```php
use Skrybe\Skrybe;

$skrybe = new Skrybe(getenv('SKRYBE_API_KEY'));

$skrybe->emails->sendTransactional([
    'toEmail' => 'ada@example.com',
    'subject' => 'Your code',
    'plainBody' => 'Your code is 123456',
]);
```

Use a brand's API key, from Settings in the Skrybe dashboard, and keep it
server-side. See [examples/quickstart.php](examples/quickstart.php) for a
runnable tour.

### Options

```php
$skrybe = new Skrybe($apiKey, [
    'baseUrl' => 'https://dashboard.skry.be', // the default
    'timeout' => 30,          // seconds per attempt
    'maxRetries' => 2,        // reads only; see below
    'httpOptions' => ['proxy' => 'http://proxy:3128'], // any Guzzle request option, e.g. a `verify` CA bundle
    'logger' => $psr3Logger,  // method, path, status and timing; never bodies
    'handler' => $handlerStack, // a Guzzle handler, e.g. a MockHandler in your tests
]);
```

Reads are retried after a dropped connection or a 429/502/503/504, honouring
`Retry-After`. Writes are never retried: the API has no idempotency keys, so a
retried send could go out twice.

## Usage

Parameters use camelCase keys. Results are arrays shaped as the API returns
them, so their keys are snake_case. Each method's docblock lists both.

### Email

```php
// One email, delivered immediately. For OTPs, password resets and receipts.
$skrybe->emails->sendTransactional([
    'toEmail' => 'ada@example.com',
    'subject' => 'Reset your password',
    'htmlBody' => '<p>...</p>',
    // fromName, fromEmail and replyTo fall back to the brand's.
]);

// A bulk send to up to 250 addresses, or to lists. Returns ['message' => ..., 'campaignId' => ...].
$skrybe->emails->send([
    'fromName' => 'Ada',
    'fromEmail' => 'ada@example.com',
    'replyTo' => 'ada@example.com',
    'subject' => 'Hello [recipient.first]',
    'htmlText' => '<p>Hi [recipient.first]</p>',
    'to' => ['grace@example.com'],
    'recipientVariables' => ['grace@example.com' => ['first' => 'Grace']],
]);
```

### Campaigns

```php
$created = $skrybe->campaigns->create([
    'fromName' => 'Ada', 'fromEmail' => 'ada@example.com', 'replyTo' => 'ada@example.com',
    'title' => 'October news', 'subject' => 'What we shipped', 'htmlText' => $html,
]);
$id = (int) $created['campaignId'];

$skrybe->campaigns->testSend($id, ['me@example.com']);                     // up to 5 addresses
$skrybe->campaigns->send($id, ['listIds' => [$listId], 'dryRun' => true]); // count recipients, send nothing
$skrybe->campaigns->send($id, ['listIds' => [$listId]]);
// or
$skrybe->campaigns->schedule($id, ['listIds' => [$listId], 'at' => '2027-06-15 18:05', 'timezone' => 'Africa/Lagos']);
$skrybe->campaigns->unschedule($id);

$skrybe->campaigns->list(status: 'sent', limit: 20);
$skrybe->campaigns->listAll();
$skrybe->campaigns->get($id, includeContent: true);
$skrybe->campaigns->update($id, ['subject' => 'New subject']);
$skrybe->campaigns->duplicate($id, title: 'Copy');
$skrybe->campaigns->delete($id);

$skrybe->campaigns->stats($id);
$skrybe->campaigns->activity($id, 'clicks', page: 1, limit: 100);
$skrybe->campaigns->activityAll($id, 'opens');
```

### Lists, subscribers and custom fields

A list id is the encrypted value `lists->list()` returns, not the number in
the dashboard URL.

```php
$lists = $skrybe->lists->list();                  // [['id' => ..., 'name' => ...], ...]
$list = $skrybe->lists->create('Customers', 'double');
$skrybe->lists->update($list['id'], ['name' => 'All customers']);
$skrybe->lists->addCustomField($list['id'], 'Birthday', 'date');
$skrybe->lists->renameCustomField($list['id'], 'Birthday', 'DOB');
$skrybe->lists->deleteCustomField($list['id'], 'DOB');
$skrybe->lists->activeSubscriberCount($list['id']);
$skrybe->lists->delete($list['id']);

$skrybe->subscribers->subscribe([
    'listId' => $listId,
    'email' => 'ada@example.com',
    'name' => 'Ada',
    'fields' => ['Birthday' => '1990-12-10'],
]); // 'subscribed' or 'already_subscribed'
$skrybe->subscribers->status($listId, 'ada@example.com');
$skrybe->subscribers->list($listId, status: 'active', page: 1, limit: 100);
$skrybe->subscribers->listAll($listId);
$skrybe->subscribers->unsubscribe($listId, 'ada@example.com');
$skrybe->subscribers->delete($listId, 'ada@example.com');
```

### Templates

```php
$t = $skrybe->templates->create(['name' => 'Welcome', 'htmlText' => $html]);
$skrybe->templates->list();
$skrybe->templates->get($t['id'], includeContent: true);
$skrybe->templates->update($t['id'], ['name' => 'Welcome v2']);
$skrybe->templates->delete($t['id']);
```

### Autoresponders

```php
$ar = $skrybe->autoresponders->create(['name' => 'Onboarding', 'type' => 'drip', 'listId' => $listId]);
$skrybe->autoresponders->createEmail($ar['id'], [
    'subject' => 'Day 3',
    'htmlText' => $html,
    'when' => '+3 days',
    'enabled' => true, // emails are created off unless this is set
]);
$skrybe->autoresponders->list($listId);
$skrybe->autoresponders->get($ar['id']);      // with its emails under email_list
$skrybe->autoresponders->emailStats($emailId);
```

### Brands and stats

```php
$skrybe->brands->list();               // the key's own brand
$skrybe->stats->totalEmailsSent();     // install-wide, public
```

## Errors

Every failure is a `Skrybe\Exception\SkrybeException`:

| Exception | When |
|---|---|
| `AuthenticationException` | The API key is missing or wrong. |
| `InvalidRequestException` | The request is wrong; retrying it unchanged fails again. Includes `missing_params`, caught before anything is sent. |
| `RateLimitException` | A quota or rate limit was hit. |
| `ConnectionException` | The API could not be reached (`network_error`) or did not answer in time (`timeout`). |
| `ApiException` | Anything else the API refused, e.g. `campaign_not_found`, or a response it could not parse. |

```php
use Skrybe\Exception\ApiException;
use Skrybe\Exception\RateLimitException;

try {
    $skrybe->campaigns->send($id, ['listIds' => [$listId]]);
} catch (RateLimitException $e) {
    // e.g. quota_exceeded
} catch (ApiException $e) {
    if ($e->getErrorCode() === 'campaign_not_draft') {
        // already sent
    }
    error_log($e->getMessage() . ' ' . $e->getHint());
}
```

Each exception has `getErrorCode()` (a stable slug), `getHint()` (what to do
about it, when known), `getStatus()` (HTTP status, when there was a response)
and `getRaw()` (the response body).

## Upgrading from 1.x

2.0 is a rewrite. Methods moved onto resources, and they return parsed values
instead of raw response bodies:

| 1.x | 2.x |
|---|---|
| `new SkrybeSDK($key, $baseUrl, $handler)` | `new Skrybe($key, ['baseUrl' => ..., 'handler' => ...])` |
| `sendEmail($opts)` | `emails->send($opts)`, returns `['message', 'campaignId']` |
| `sendTransactionalEmail($opts)` | `emails->sendTransactional($opts)`, returns `['message', 'messageId']` |
| `createCampaign($opts)` | `campaigns->create($opts)`. `'sendCampaign' => 1` is `'send' => true`. Returns `['outcome', 'campaignId']`. |
| `getLists($includeHidden)` | `lists->list($includeHidden)`, returns `[['id', 'name'], ...]` |
| `getCampaigns($opts)` | `campaigns->list(page:, limit:, status:)` |
| `getSubscribers($listId, $opts)` | `subscribers->list($listId, status:, page:, limit:)` |
| `addSubscriber($listId, $data)` | `subscribers->subscribe(['listId' => $listId, 'email' => ...])` (1.x called an endpoint that did not exist) |
| `setLogger($logger)` | Unchanged, or pass `'logger'` in the options. |

Failures now throw instead of returning an error string, and
`ValidationException` is gone: a missing parameter throws
`InvalidRequestException` with the code `missing_params`.

## License

MIT
