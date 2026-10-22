<?php

namespace Skrybe\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Skrybe\SkrybeSDK;
use Skrybe\Exception\SkrybeException;

class SkrybeSDKTest extends TestCase
{
    private $sdk;
    private $mockHandler;
    private $history = [];

    protected function setUp(): void
    {
        // MockHandler throws on an unqueued request, so no test can reach the
        // real API.
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->history = [];
        $handlerStack->push(Middleware::history($this->history));

        $this->sdk = new SkrybeSDK('test-api-key', 'https://skrybe.test', $handlerStack);
    }

    private function lastRequest()
    {
        return end($this->history)['request'];
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

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://skrybe.test/api/emails/send.php', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('test-api-key', $form['api_key']);
        $this->assertSame('Test Subject', $form['subject']);
        $this->assertSame('["recipient@example.com"]', $form['to']);
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

    public function testSendTransactionalEmail()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'status' => 'success',
                'message' => 'Email sent successfully.'
            ]))
        );

        $result = $this->sdk->sendTransactionalEmail([
            'toEmail' => 'user@example.com',
            'subject' => 'Your one-time passcode',
            'htmlBody' => '<p>Your code is <strong>384092</strong>. It expires in 5 minutes.</p>',
            'plainBody' => 'Your code is 384092. It expires in 5 minutes.'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Email sent successfully.', $result['message']);

        $request = $this->lastRequest();
        $this->assertSame('/api/emails/send-transactional.php', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('test-api-key', $payload['api_key']);
        $this->assertSame('user@example.com', $payload['to_email']);
        $this->assertArrayNotHasKey('from_name', $payload);
    }

    public function testSendTransactionalEmailWithOptionalParams()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'status' => 'success',
                'message' => 'Email sent successfully.'
            ]))
        );

        $result = $this->sdk->sendTransactionalEmail([
            'toEmail' => 'user@example.com',
            'subject' => 'Your one-time passcode',
            'htmlBody' => '<p>Your code is <strong>384092</strong>.</p>',
            'fromName' => 'Skrybe Security',
            'fromEmail' => 'no-reply@yourdomain.com',
            'replyTo' => 'support@yourdomain.com'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
    }

    public function testApiErrorIsWrappedInSkrybeException()
    {
        $this->mockHandler->append(new Response(401, [], 'Invalid API key'));

        $this->expectException(SkrybeException::class);
        $this->expectExceptionCode(401);

        $this->sdk->getLists();
    }

    public function testBackToBackRequestsAreRateLimitedWithoutError()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['lists' => []])),
            new Response(200, [], json_encode(['lists' => []]))
        );

        $start = microtime(true);
        $this->sdk->getLists();
        $this->sdk->getLists();

        $this->assertCount(2, $this->history);
        $this->assertGreaterThanOrEqual(0.09, microtime(true) - $start);
    }

    public function testSendTransactionalEmailValidationRequiresToEmail()
    {
        $this->expectException(\Skrybe\Exception\ValidationException::class);

        $this->sdk->sendTransactionalEmail([
            'subject' => 'Test Subject',
            'htmlBody' => '<p>Test</p>'
        ]);
    }

    public function testSendTransactionalEmailValidationRequiresSubject()
    {
        $this->expectException(\Skrybe\Exception\ValidationException::class);

        $this->sdk->sendTransactionalEmail([
            'toEmail' => 'user@example.com',
            'htmlBody' => '<p>Test</p>'
        ]);
    }

    public function testSendTransactionalEmailValidationRequiresBody()
    {
        $this->expectException(\Skrybe\Exception\ValidationException::class);

        $this->sdk->sendTransactionalEmail([
            'toEmail' => 'user@example.com',
            'subject' => 'Test Subject'
        ]);
    }

    public function testSendTransactionalEmailAcceptsPlainBodyOnly()
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'status' => 'success',
                'message' => 'Email sent successfully.'
            ]))
        );

        $result = $this->sdk->sendTransactionalEmail([
            'toEmail' => 'user@example.com',
            'subject' => 'Plain text email',
            'plainBody' => 'This is a plain text email.'
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
    }
}
