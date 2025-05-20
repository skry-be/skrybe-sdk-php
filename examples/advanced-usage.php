<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Skrybe\SkrybeSDK;
use Skrybe\Exception\SkrybeException;
use Skrybe\Exception\ValidationException;

// Create a logger
$logger = new Logger('skrybe');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/skrybe.log', Logger::DEBUG));

// Initialize the SDK with logging
try {
    $sdk = new SkrybeSDK('your-api-key-here');
    $sdk->setLogger($logger);

    // Example 1: Send an email with error handling
    try {
        $emailResult = $sdk->sendEmail([
            'fromName' => 'Newsletter Team',
            'fromEmail' => 'newsletter@yourdomain.com',
            'replyTo' => 'support@yourdomain.com',
            'subject' => 'Welcome to Our Newsletter!',
            'htmlText' => file_get_contents(__DIR__ . '/templates/welcome.html'),
            'to' => ['user@example.com'],
            'trackOpens' => true,
            'trackClicks' => true
        ]);
        
        echo "Email sent successfully!\n";
        print_r($emailResult);
    } catch (ValidationException $e) {
        echo "Validation error:\n";
        print_r($e->getErrors());
    } catch (SkrybeException $e) {
        echo "Failed to send email: " . $e->getMessage() . "\n";
    }

    // Example 2: Create and send a campaign to a list
    try {
        // First, get all lists
        $lists = $sdk->getLists();
        
        if (!empty($lists['lists'])) {
            $firstList = $lists['lists'][0];
            
            // Create a campaign for the first list
            $campaign = $sdk->createCampaign([
                'fromName' => 'Newsletter Team',
                'fromEmail' => 'newsletter@yourdomain.com',
                'replyTo' => 'support@yourdomain.com',
                'title' => 'Monthly Newsletter - ' . date('F Y'),
                'subject' => 'Your Monthly Update Is Here!',
                'htmlText' => file_get_contents(__DIR__ . '/templates/monthly.html'),
                'listIds' => [$firstList['id']],
                'trackOpens' => true,
                'trackClicks' => true,
                'scheduleDateTime' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'scheduleTimezone' => 'UTC'
            ]);
            
            echo "Campaign scheduled successfully!\n";
            print_r($campaign);
        }
    } catch (ValidationException $e) {
        echo "Campaign validation error:\n";
        print_r($e->getErrors());
    } catch (SkrybeException $e) {
        echo "Failed to create campaign: " . $e->getMessage() . "\n";
    }

    // Example 3: Manage subscribers
    try {
        // Add a new subscriber
        $newSubscriber = $sdk->addSubscriber('list-id-here', [
            'email' => 'new.subscriber@example.com',
            'name' => 'John Doe',
            'custom_fields' => [
                'company' => 'ACME Inc',
                'interests' => ['technology', 'marketing']
            ]
        ]);
        
        echo "Subscriber added successfully!\n";
        print_r($newSubscriber);

        // Get list subscribers with pagination
        $subscribers = $sdk->getSubscribers('list-id-here', [
            'page' => 1,
            'limit' => 50,
            'status' => 'active'
        ]);
        
        echo "Active subscribers:\n";
        print_r($subscribers);
        
    } catch (SkrybeException $e) {
        echo "Subscriber management error: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "SDK initialization error: " . $e->getMessage() . "\n";
}
