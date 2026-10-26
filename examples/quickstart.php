<?php

/**
 * A read-only tour of the SDK. Nothing is sent or changed unless TO is set,
 * in which case one transactional email goes to that address.
 *
 *   SKRYBE_API_KEY=... php examples/quickstart.php
 *   SKRYBE_API_KEY=... TO=you@example.com php examples/quickstart.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Skrybe\Exception\SkrybeException;
use Skrybe\Skrybe;

$skrybe = new Skrybe((string) getenv('SKRYBE_API_KEY'), [
    'baseUrl' => getenv('SKRYBE_BASE_URL') ?: Skrybe::DEFAULT_BASE_URL,
]);

try {
    foreach ($skrybe->brands->list() as $brand) {
        echo "Brand: {$brand['name']}\n";
    }

    foreach ($skrybe->lists->list() as $list) {
        $count = $skrybe->lists->activeSubscriberCount($list['id']);
        echo "List: {$list['name']} ({$count} active)\n";
    }

    foreach ($skrybe->campaigns->list(limit: 5) as $campaign) {
        echo "Campaign #{$campaign['id']}: {$campaign['title']} [{$campaign['status']}]\n";
    }

    if ($to = getenv('TO')) {
        $sent = $skrybe->emails->sendTransactional([
            'toEmail' => $to,
            'subject' => 'Hello from the Skrybe PHP SDK',
            'plainBody' => 'It works.',
        ]);
        echo "Sent: {$sent['message']}\n";
    }
} catch (SkrybeException $e) {
    fwrite(STDERR, "{$e->getErrorCode()}: {$e->getMessage()}\n");
    if ($e->getHint()) {
        fwrite(STDERR, "Hint: {$e->getHint()}\n");
    }
    exit(1);
}
