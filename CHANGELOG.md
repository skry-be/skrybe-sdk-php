# Changelog

All notable changes to the Skrybe PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Require a PHP release that fixes CVE-2026-109618 and CVE-2026-109619
  (unauthenticated RCE, 2026-10-21): 8.2.35, 8.3.36, 8.4.27 or 8.5.12 and up.
  PHP 7.4 through 8.1 receive no fix and are no longer supported.

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
