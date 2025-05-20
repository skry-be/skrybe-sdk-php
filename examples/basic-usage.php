<?php

require_once __DIR__ . '/vendor/autoload.php';

use Skrybe\SkrybeSDK;
use Skrybe\Exception\SkrybeException;

// Create an instance of the SDK
$sdk = new SkrybeSDK('your-api-key-here');

try {
    // Example 1: Send an email
    $emailResult = $sdk->sendEmail([
        'fromName' => 'John Doe',
        'fromEmail' => 'john@example.com',
        'replyTo' => 'reply@example.com',
        'subject' => 'Test Email',
        'htmlText' => '<h1>Hello World!</h1><p>This is a test email.</p>',
        'to' => ['recipient@example.com']
    ]);
    
    echo "Email sent successfully!\n";
    print_r($emailResult);

    // Example 2: Get all lists
    $lists = $sdk->getLists();
    echo "\nAvailable lists:\n";
    print_r($lists);

    // Example 3: Create a campaign
    $campaign = $sdk->createCampaign([
        'fromName' => 'John Doe',
        'fromEmail' => 'john@example.com',
        'replyTo' => 'reply@example.com',
        'title' => 'Test Campaign',
        'subject' => 'Welcome to Our Newsletter',
        'htmlText' => '<h1>Welcome!</h1><p>Thank you for subscribing.</p>',
        'listIds' => ['your-list-id-here']
    ]);
    
    echo "\nCampaign created successfully!\n";
    print_r($campaign);

    // Example 4: Get subscribers from a list
    $subscribers = $sdk->getSubscribers('your-list-id-here', [
        'page' => 1,
        'limit' => 10
    ]);
    
    echo "\nSubscribers:\n";
    print_r($subscribers);

} catch (SkrybeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
