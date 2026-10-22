# Changelog

All notable changes to the Skrybe PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
