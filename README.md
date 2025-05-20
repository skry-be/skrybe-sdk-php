# Skrybe PHP SDK

A PHP SDK for interacting with the Skrybe Email Newsletter Service. This SDK provides a simple and elegant way to integrate email newsletter functionality into your PHP applications.

## Requirements

- PHP 7.4 or higher
- Composer
- GuzzleHttp 7.0 or higher
- PSR-3 compatible logger (optional)

## Installation

1. Install via Composer:
```bash
composer require skrybe/sdk-php
```

2. (Optional) Install Monolog for logging:
```bash
composer require monolog/monolog
```

## Usage

```php
use Skrybe\SkrybeSDK;

$sdk = new SkrybeSDK('your-api-key');

// Send an email
$response = $sdk->sendEmail([
    'fromName' => 'John Doe',
    'fromEmail' => 'john@example.com',
    'replyTo' => 'reply@example.com',
    'subject' => 'Hello World',
    'htmlText' => '<h1>Hello World</h1>',
    'to' => ['user@example.com']
]);

// Get lists
$lists = $sdk->getLists();

// Create a campaign
$campaign = $sdk->createCampaign([
    'fromName' => 'John Doe',
    'fromEmail' => 'john@example.com',
    'replyTo' => 'reply@example.com',
    'title' => 'My Campaign',
    'subject' => 'Hello World',
    'htmlText' => '<h1>Hello World</h1>',
    'listIds' => ['list-id-1', 'list-id-2']
]);
```

## Available Methods

### Email Operations
- `sendEmail(array $options)` - Send an email to recipients
- `createCampaign(array $options)` - Create an email campaign
- `getLists(bool $includeHidden = false)` - Get all mailing lists

## Documentation
For detailed documentation, please see the [docs](./docs) directory.
