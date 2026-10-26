# Changelog

All notable changes to the Skrybe PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-10-26

A rewrite covering the whole public API, in step with `@skrybe/sdk` 2.0.0 and
`@skrybe/cli` 0.2.0. See "Upgrading from 1.x" in the README.

### Added
- A `Skrybe` client with resources: `emails`, `campaigns`, `lists`,
  `subscribers`, `templates`, `autoresponders`, `brands`, `sms`, `stats`.
- Campaigns: `list`/`listAll`, `get`, `stats`, `send` (with `dryRun`),
  `testSend`, `schedule`/`unschedule`, `update`, `duplicate`, `delete`,
  `activity`/`activityAll`.
- Lists: `get`, `create`, `update`, `delete`, `activeSubscriberCount`, and
  custom fields (`addCustomField`, `renameCustomField`, `deleteCustomField`).
- Subscribers: `subscribe` (with custom fields and phone), `unsubscribe`,
  `delete`, `status`, `list`/`listAll`.
- Templates: `list`, `get`, `create`, `update`, `delete`.
- Autoresponders: `list`, `get`, `emailStats`, `create`, `createEmail`.
- SMS: `send` and `get`.
- `brands->list()` and `stats->totalEmailsSent()`.
- Typed exceptions under `SkrybeException`: `AuthenticationException`,
  `InvalidRequestException`, `RateLimitException`, `ConnectionException` and
  `ApiException`, each with a stable `getErrorCode()` and, where known, a
  `getHint()`. Prose failures the API returns with HTTP 200 are recognised and
  thrown.
- `timeout`, `maxRetries` (reads only), `httpOptions` (proxy, CA bundle) and
  `logger` options.

### Changed
- `SkrybeSDK` is now `Skrybe`, and options are passed as an array:
  `new Skrybe($key, ['baseUrl' => ...])`.
- Methods return parsed results and throw on failure, instead of returning
  whatever the response body was.
- `campaigns->create()` takes `send => true` instead of `sendCampaign => 1`.

### Removed
- `ValidationException`; missing parameters throw `InvalidRequestException`
  with the code `missing_params`.
- The client-side 100ms request spacing.

### Fixed
- `addSubscriber()` called `api/subscribers/add.php`, which does not exist;
  `subscribers->subscribe()` replaces it.
- `createCampaign()` sent list and segment ids JSON-encoded; they are
  comma-joined, as the API expects.
- `getLists()` threw a `TypeError` for a brand without lists, and could not
  read list names containing quotes.
- Request bodies, API key included, are no longer written to the logger.

## [1.1.0] - 2026-10-22

### Added
- `sendTransactionalEmail()` for immediate delivery of single transactional
  emails (OTP, password reset, alerts)
- Optional Guzzle `$handler` third constructor argument, for injecting a mock
  handler in tests
- MIT License file

### Fixed
- Two SDK calls within 100ms threw `ValueError` on PHP 8: the rate limiter read
  an undefined property instead of `MIN_REQUEST_INTERVAL`
- The test suite no longer sends requests to the live API

### Security
- Require a PHP release that fixes CVE-2026-109618 and CVE-2026-109619
  (unauthenticated RCE, 2026-10-21): 8.2.35, 8.3.36, 8.4.27 or 8.5.12 and up.
  PHP 7.4 through 8.1 receive no fix and are no longer supported.
- Dev dependency: PHPUnit bumped to ^12.5.22 (GHSA-qrr6-mg7r-m243)

## [1.0.0] - 2025-05-20

### Added
- Initial release of the Skrybe PHP SDK
- Email sending functionality
- Campaign management
- List management
- Subscriber management
- Input validation
- Rate limiting protection
- PSR-3 logging support
- Comprehensive documentation
- Unit tests
- Example templates and usage scripts

### Security
- Input validation for email addresses
- Rate limiting to prevent API abuse
- Secure API key handling
