# Skrybe SDK Documentation

## Installation

Install the SDK using Composer:

```bash
composer require skrybe/sdk-php
```

## Configuration

Initialize the SDK with your API key:

```php
use Skrybe\SkrybeSDK;

$sdk = new SkrybeSDK('your-api-key');
```

## Available Methods

### Email Operations

#### Send Email
```php
$response = $sdk->sendEmail([
    'fromName' => 'John Doe',
    'fromEmail' => 'john@example.com',
    'replyTo' => 'reply@example.com',
    'subject' => 'Hello World',
    'htmlText' => '<h1>Hello World</h1>',
    'to' => ['user@example.com'],
    'plainText' => 'Hello World', // optional
    'recipientVariables' => [], // optional
    'listIds' => ['list-1', 'list-2'], // optional
    'trackOpens' => true, // optional
    'trackClicks' => true, // optional
    'scheduleDateTime' => '2025-05-21 10:00:00', // optional
    'scheduleTimezone' => 'UTC' // optional
]);
```

#### Create Campaign
```php
$campaign = $sdk->createCampaign([
    'fromName' => 'John Doe',
    'fromEmail' => 'john@example.com',
    'replyTo' => 'reply@example.com',
    'title' => 'Campaign Title',
    'subject' => 'Email Subject',
    'htmlText' => '<h1>Campaign Content</h1>',
    'plainText' => 'Campaign Content', // optional
    'listIds' => ['list-1'], // optional
    'segmentIds' => ['segment-1'], // optional
    'excludeListIds' => ['list-2'], // optional
    'excludeSegmentIds' => ['segment-2'], // optional
    'trackOpens' => true, // optional
    'trackClicks' => true, // optional
    'sendCampaign' => true, // optional
    'scheduleDateTime' => '2025-05-21 10:00:00', // optional
    'scheduleTimezone' => 'UTC' // optional
]);
```

### List Management

#### Get Lists
```php
$lists = $sdk->getLists($includeHidden = false);
```

#### Get Subscribers
```php
$subscribers = $sdk->getSubscribers('list-id', [
    'page' => 1, // optional
    'limit' => 10, // optional
    'status' => 'active' // optional
]);
```

#### Add Subscriber
```php
$subscriber = $sdk->addSubscriber('list-id', [
    'email' => 'user@example.com',
    'name' => 'John Doe',
    'custom_fields' => [
        'company' => 'Example Inc',
        'role' => 'Developer'
    ]
]);
```

### Campaign Management

#### Get Campaigns
```php
$campaigns = $sdk->getCampaigns([
    'page' => 1, // optional
    'limit' => 10, // optional
    'status' => 'sent' // optional
]);
```

## Error Handling

The SDK throws `Skrybe\Exception\SkrybeException` for any API errors. Always wrap your API calls in try-catch blocks:

```php
try {
    $response = $sdk->sendEmail([...]);
} catch (Skrybe\Exception\SkrybeException $e) {
    // Handle error
    echo $e->getMessage();
}
```
