<?php

namespace Skrybe\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Skrybe\SkrybeSDK;
use Skrybe\Exception\SkrybeException;

class SkrybeSDKTest extends TestCase
{
    private $sdk;
    private $mockHandler;

    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $client = new Client(['handler' => $handlerStack]);
        
        $this->sdk = new SkrybeSDK('test-api-key');
        // TODO: Inject mock client into SDK
    }

    public function testSendEmail()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['success' => true]))
        );

        $result = $this->sdk->sendEmail([
            'fromName' => 'Test Name',
            'fromEmail' => 'test@example.com',
            'replyTo' => 'reply@example.com',
            'subject' => 'Test Subject',
            'htmlText' => '<p>Test Content</p>',
            'to' => ['recipient@example.com']
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testCreateCampaign()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'success' => true,
                'campaign_id' => '123456'
            ]))
        );

        $result = $this->sdk->createCampaign([
            'fromName' => 'Test Name',
            'fromEmail' => 'test@example.com',
            'replyTo' => 'reply@example.com',
            'title' => 'Test Campaign',
            'subject' => 'Test Subject',
            'htmlText' => '<p>Test Content</p>',
            'listIds' => ['list-123']
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('123456', $result['campaign_id']);
    }

    public function testGetLists()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'lists' => [
                    ['id' => 'list-1', 'name' => 'List 1'],
                    ['id' => 'list-2', 'name' => 'List 2']
                ]
            ]))
        );

        $result = $this->sdk->getLists();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lists', $result);
        $this->assertCount(2, $result['lists']);
    }

    public function testGetSubscribers()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'subscribers' => [
                    ['email' => 'sub1@example.com', 'name' => 'Sub 1'],
                    ['email' => 'sub2@example.com', 'name' => 'Sub 2']
                ]
            ]))
        );

        $result = $this->sdk->getSubscribers('list-123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('subscribers', $result);
        $this->assertCount(2, $result['subscribers']);
    }

    public function testAddSubscriber()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'success' => true,
                'subscriber_id' => '789'
            ]))
        );

        $result = $this->sdk->addSubscriber('list-123', [
            'email' => 'new@example.com',
            'name' => 'New Subscriber'
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('789', $result['subscriber_id']);
    }
}
