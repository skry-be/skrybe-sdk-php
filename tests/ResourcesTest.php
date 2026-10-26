<?php

namespace Skrybe\Tests;

use Skrybe\Exception\ApiException;
use Skrybe\Exception\InvalidRequestException;
use Skrybe\Skrybe;

class ResourcesTest extends TestCase
{
    private const DRAFT = ['fromName' => 'a', 'fromEmail' => 'b@c.d', 'replyTo' => 'b@c.d', 'title' => 't', 'subject' => 's', 'htmlText' => 'h'];

    // ---------------------------------------------------------------- emails

    public function testSendPostsRecipientsAsJsonStringsInsideAFormBody(): void
    {
        $skrybe = $this->client(['{"ok":true,"message":"Queued","data":{"campaign_id":"c1"}}']);

        $result = $skrybe->emails->send([
            'fromName' => 'Ada',
            'fromEmail' => 'ada@example.com',
            'replyTo' => 'ada@example.com',
            'subject' => 'Hi',
            'htmlText' => '<p>Hi [recipient.first]</p>',
            'to' => ['a@example.com'],
            'recipientVariables' => ['a@example.com' => ['first' => 'A']],
            'trackOpens' => 1,
        ]);

        $this->assertSame(['message' => 'Queued', 'campaignId' => 'c1'], $result);
        $this->assertSame('/api/emails/send.php', $this->path());
        $this->assertSame([
            'from_name' => 'Ada',
            'from_email' => 'ada@example.com',
            'reply_to' => 'ada@example.com',
            'subject' => 'Hi',
            'html_text' => '<p>Hi [recipient.first]</p>',
            'to' => '["a@example.com"]',
            'recipient-variables' => '{"a@example.com":{"first":"A"}}',
            'track_opens' => '1',
        ], $this->sent());
    }

    public function testSendRefusesBeforeAnyRequestWithNobodyOrTooManyToSendTo(): void
    {
        $skrybe = $this->client();
        $base = ['fromName' => 'a', 'fromEmail' => 'b', 'replyTo' => 'c', 'subject' => 'd', 'htmlText' => 'e'];

        foreach ([$base, $base + ['to' => array_map(fn ($i) => "u{$i}@example.com", range(0, 250))]] as $params) {
            try {
                $skrybe->emails->send($params);
                $this->fail('expected an exception');
            } catch (InvalidRequestException) {
            }
        }
        $this->assertCount(0, $this->history);
    }

    public function testMissingRequiredParamsAreCaughtBeforeSending(): void
    {
        $skrybe = $this->client();
        try {
            $skrybe->campaigns->create(['title' => 't']);
            $this->fail('expected an exception');
        } catch (InvalidRequestException $e) {
            $this->assertSame('missing_params', $e->getErrorCode());
            $this->assertStringContainsString('fromName', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function testSendTransactionalPostsRawJson(): void
    {
        $skrybe = $this->client(['{"status":"success","message":"Sent","message_id":"m1"}']);

        $result = $skrybe->emails->sendTransactional(['toEmail' => 'a@example.com', 'subject' => 'OTP', 'plainBody' => '1234']);

        $this->assertSame(['message' => 'Sent', 'messageId' => 'm1'], $result);
        $this->assertSame('application/json', $this->request()->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['to_email' => 'a@example.com', 'subject' => 'OTP', 'plain_body' => '1234', 'api_key' => self::KEY],
            json_decode((string) $this->request()->getBody(), true),
        );
    }

    public function testSendTransactionalNeedsABody(): void
    {
        $skrybe = $this->client();
        $this->expectException(InvalidRequestException::class);
        $skrybe->emails->sendTransactional(['toEmail' => 'a@b.c', 'subject' => 's']);
    }

    // ------------------------------------------------------------- campaigns

    public function testCreateReturnsTheNewIdFromTheJsonAnswer(): void
    {
        $skrybe = $this->client(['{"status":"Campaign created","campaign_id":"42"}']);

        $this->assertSame(['outcome' => 'created', 'campaignId' => '42'], $skrybe->campaigns->create(self::DRAFT + ['listIds' => ['L1', 'L2']]));
        $fields = $this->sent();
        $this->assertSame('1', $fields['json']);
        $this->assertSame('L1,L2', $fields['list_ids']);
        $this->assertArrayNotHasKey('send_campaign', $fields);
    }

    public function testCreateReportsSendingAndScheduledOutcomes(): void
    {
        $skrybe = $this->client(['Campaign created and now sending']);
        $this->assertSame(['outcome' => 'sending'], $skrybe->campaigns->create(self::DRAFT + ['listIds' => ['L'], 'send' => true]));
        $this->assertSame('1', $this->sent()['send_campaign']);

        $skrybe = $this->client(['Campaign scheduled']);
        $this->assertSame(['outcome' => 'scheduled'], $skrybe->campaigns->create(self::DRAFT + ['scheduleDateTime' => 'June 1, 2027 6:05pm']));
    }

    public function testCreateDoesNotMistakeAnUnknownBodyForSuccess(): void
    {
        $skrybe = $this->client(['<html>Bad gateway</html>']);
        try {
            $skrybe->campaigns->create(self::DRAFT);
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame('unexpected_response', $e->getErrorCode());
        }
    }

    public function testListReadsNumberedRecordsAndNoCampaignsFoundAsEmpty(): void
    {
        $row = ['id' => 3, 'title' => 'T', 'status' => 'sent'];
        $skrybe = $this->client([json_encode(['campaign1' => $row])]);
        $this->assertSame([$row], $skrybe->campaigns->list(limit: 10, status: 'sent'));
        $this->assertSame(['limit' => '10', 'status' => 'sent'], $this->sent());

        $skrybe = $this->client(['No campaigns found']);
        $this->assertSame([], $skrybe->campaigns->list());
    }

    public function testListAllWalksPagesUntilAShortOne(): void
    {
        $page = fn (int $n) => json_encode(array_combine(
            array_map(fn ($i) => 'campaign' . ($i + 1), range(0, $n - 1)),
            array_map(fn ($i) => ['id' => $i], range(0, $n - 1)),
        ));
        $skrybe = $this->client([$page(100), $page(3)]);

        $this->assertCount(103, $skrybe->campaigns->listAll());
        $pages = array_map(function ($entry) {
            parse_str((string) $entry['request']->getBody(), $f);
            return $f['page'];
        }, $this->history);
        $this->assertSame(['1', '2'], $pages);
    }

    public function testSendAndSchedulePassRecipientsAndDryRunOnlyWhenAsked(): void
    {
        $skrybe = $this->client(['{"status":"sending","campaign_id":5,"recipients":10}']);
        $skrybe->campaigns->send(5, ['listIds' => ['A'], 'segmentIds' => [7], 'excludeListIds' => ['B']]);
        $this->assertSame(['campaign_id' => '5', 'list_ids' => 'A', 'segment_ids' => '7', 'exclude_list_ids' => 'B'], $this->sent());

        $skrybe = $this->client(['{"status":"dry_run","campaign_id":5,"recipients":10,"scheduled_at":1,"timezone":"Africa/Lagos"}']);
        $skrybe->campaigns->schedule(5, ['listIds' => ['A'], 'at' => '2027-06-15 18:05', 'timezone' => 'Africa/Lagos', 'dryRun' => true]);
        $this->assertSame([
            'campaign_id' => '5',
            'list_ids' => 'A',
            'dry_run' => '1',
            'schedule_date_time' => '2027-06-15 18:05',
            'schedule_timezone' => 'Africa/Lagos',
        ], $this->sent());
    }

    public function testEachCampaignEndpointPostsToItsOwnPath(): void
    {
        $calls = [
            '/api/campaigns/get-campaign.php' => fn (Skrybe $s) => $s->campaigns->get(1, includeContent: true),
            '/api/campaigns/stats.php' => fn (Skrybe $s) => $s->campaigns->stats(1),
            '/api/campaigns/test-send.php' => fn (Skrybe $s) => $s->campaigns->testSend(1, ['a@b.c']),
            '/api/campaigns/unschedule.php' => fn (Skrybe $s) => $s->campaigns->unschedule(1),
            '/api/campaigns/update-campaign.php' => fn (Skrybe $s) => $s->campaigns->update(1, ['subject' => 'x']),
            '/api/campaigns/duplicate-campaign.php' => fn (Skrybe $s) => $s->campaigns->duplicate(1),
            '/api/campaigns/delete-campaign.php' => fn (Skrybe $s) => $s->campaigns->delete(1),
            '/api/campaigns/get-activity.php' => fn (Skrybe $s) => $s->campaigns->activity(1, 'clicks'),
        ];
        foreach ($calls as $path => $call) {
            $skrybe = $this->client(['{"campaign_id":1}']);
            $call($skrybe);
            $this->assertSame($path, $this->path());
            $this->assertSame('1', $this->sent()['campaign_id']);
        }
    }

    public function testActivityAllStopsAtTheTotal(): void
    {
        $page = fn (int $n, int $total) => json_encode(['activity' => array_fill(0, $n, ['email' => 'a@b.c']), 'total' => $total]);
        $skrybe = $this->client([$page(1000, 1500), $page(500, 1500)]);
        $this->assertCount(1500, $skrybe->campaigns->activityAll(1, 'opens'));
        $this->assertCount(2, $this->history);
    }

    // ----------------------------------------------------------------- lists

    public function testListSurvivesAnUnescapedQuoteInAName(): void
    {
        $skrybe = $this->client([self::phpNumberedPayload('list', [['id' => 'A1', 'name' => 'Q4 "Big" Push']])]);
        $this->assertSame([['id' => 'A1', 'name' => 'Q4 "Big" Push']], $skrybe->lists->list());
    }

    public function testListReturnsEmptyForABrandWithoutLists(): void
    {
        $skrybe = $this->client(['No lists found']);
        $this->assertSame([], $skrybe->lists->list());
    }

    public function testActiveSubscriberCountNeverReportsAFailureAsZero(): void
    {
        $skrybe = $this->client(['<b>Warning</b>']);
        $this->expectException(ApiException::class);
        $skrybe->lists->activeSubscriberCount('A1');
    }

    public function testManagesCustomFields(): void
    {
        $skrybe = $this->client(['{"id":"A1"}']);
        $skrybe->lists->renameCustomField('A1', 'Birthday', 'DOB');
        $this->assertSame('/api/lists/rename-custom-field.php', $this->path());
        $this->assertSame(['list_id' => 'A1', 'name' => 'Birthday', 'new_name' => 'DOB'], $this->sent());

        $skrybe = $this->client(['{"id":"A1"}']);
        $skrybe->lists->addCustomField('A1', 'DOB', 'date');
        $this->assertSame(['list_id' => 'A1', 'name' => 'DOB', 'type' => 'date'], $this->sent());
    }

    public function testCreateAndUpdateList(): void
    {
        $skrybe = $this->client(['{"id":"A1","name":"News","opt_in":"double"}']);
        $this->assertSame('A1', $skrybe->lists->create('News', 'double')['id']);
        $this->assertSame('/api/lists/create.php', $this->path());
        $this->assertSame(['name' => 'News', 'opt_in' => 'double'], $this->sent());

        $skrybe = $this->client(['{"id":"A1","name":"Renamed"}']);
        $skrybe->lists->update('A1', ['name' => 'Renamed']);
        $this->assertSame(['list_id' => 'A1', 'name' => 'Renamed'], $this->sent());
    }

    // ----------------------------------------------------------- subscribers

    public function testSubscribePostsToTheRootEndpointInPlainTextMode(): void
    {
        $skrybe = $this->client(['1']);

        $outcome = $skrybe->subscribers->subscribe([
            'listId' => 'A1',
            'email' => 'a@example.com',
            'name' => 'Ada',
            'gdpr' => true,
            'fields' => ['Favourite colour' => 'blue', 'email' => 'forged@example.com'],
        ]);

        $this->assertSame('subscribed', $outcome);
        $this->assertSame('/subscribe', $this->path());
        $this->assertSame([
            'Favouritecolour' => 'blue',
            'email' => 'a@example.com',
            'list' => 'A1',
            'boolean' => 'true',
            'name' => 'Ada',
            'gdpr' => 'true',
        ], $this->sent());
    }

    public function testSubscribeReportsAnExistingAddressRatherThanThrowing(): void
    {
        $skrybe = $this->client(['Already subscribed.']);
        $this->assertSame('already_subscribed', $skrybe->subscribers->subscribe(['listId' => 'A1', 'email' => 'a@b.c']));
    }

    public function testUnsubscribeDeleteAndStatusUseTheirOwnFieldNames(): void
    {
        $skrybe = $this->client(['true']);
        $skrybe->subscribers->unsubscribe('A1', 'a@b.c');
        $this->assertSame('/unsubscribe', $this->path());
        $this->assertSame(['email' => 'a@b.c', 'list' => 'A1', 'boolean' => 'true'], $this->sent());

        $skrybe = $this->client(['true']);
        $skrybe->subscribers->delete('A1', 'a@b.c');
        $this->assertSame(['list_id' => 'A1', 'email' => 'a@b.c'], $this->sent());

        $skrybe = $this->client(["Soft bounced\n"]);
        $this->assertSame('Soft bounced', $skrybe->subscribers->status('A1', 'a@b.c'));
    }

    public function testDoesNotReportAnUnrecognisedBodyAsSuccess(): void
    {
        $skrybe = $this->client(['Something new']);
        $this->expectException(ApiException::class);
        $skrybe->subscribers->unsubscribe('A1', 'a@b.c');
    }

    public function testListAllSubscribersWalksPagesOf1000(): void
    {
        $page = fn (int $n) => json_encode(['subscribers' => array_fill(0, $n, ['email' => 'a@b.c']), 'total' => 1001]);
        $skrybe = $this->client([$page(1000), $page(1)]);
        $this->assertCount(1001, $skrybe->subscribers->listAll('A1', 'active'));
        parse_str((string) $this->request(1)->getBody(), $fields);
        $this->assertSame(['list_id' => 'A1', 'status' => 'active', 'page' => '2', 'limit' => '1000', 'api_key' => self::KEY], $fields);
    }

    // ------------------------------------------------------------- templates

    public function testTemplateCreateMapsCamelCaseOntoTheApiFields(): void
    {
        $skrybe = $this->client(['{"id":9,"name":"Welcome"}']);
        $this->assertSame(['id' => 9, 'name' => 'Welcome'], $skrybe->templates->create(['name' => 'Welcome', 'htmlText' => '<p/>', 'editor' => 'html']));
        $this->assertSame(['name' => 'Welcome', 'html_text' => '<p/>', 'editor' => 'html'], $this->sent());
    }

    public function testTemplateListUnwrapsTheTemplatesArray(): void
    {
        $skrybe = $this->client(['{"templates":[{"id":1}]}']);
        $this->assertSame([['id' => 1]], $skrybe->templates->list());
    }

    // -------------------------------------------------------- autoresponders

    public function testAutoresponderCreatePostsTheTypeAndList(): void
    {
        $skrybe = $this->client(['{"id":4,"name":"Birthdays","type":"annually"}']);
        $skrybe->autoresponders->create(['name' => 'Birthdays', 'type' => 'annually', 'listId' => 'A1', 'customField' => 'Birthday']);
        $this->assertSame('/api/autoresponders/create-autoresponder.php', $this->path());
        $this->assertSame(['name' => 'Birthdays', 'type' => 'annually', 'list_id' => 'A1', 'custom_field' => 'Birthday'], $this->sent());
    }

    public function testCreateEmailSendsEnabledOnlyWhenAsked(): void
    {
        $skrybe = $this->client(['{"id":8,"autoresponder_id":4,"when":"+3 days"}']);
        $skrybe->autoresponders->createEmail(4, ['subject' => 'Day 3', 'htmlText' => '<p/>', 'when' => '+3 days']);
        $this->assertSame('/api/autoresponders/create-autoresponder-email.php', $this->path());
        $this->assertSame(['autoresponder_id' => '4', 'subject' => 'Day 3', 'html_text' => '<p/>', 'when' => '+3 days'], $this->sent());
    }

    public function testSurfacesTheServersWhenValidationAsAnInvalidRequest(): void
    {
        $skrybe = $this->client(['when must be immediately, or a delay such as +3 days']);
        try {
            $skrybe->autoresponders->createEmail(4, ['subject' => 's', 'htmlText' => 'h', 'when' => 'soon']);
            $this->fail('expected an exception');
        } catch (InvalidRequestException $e) {
            $this->assertSame('invalid_when', $e->getErrorCode());
        }
    }

    public function testAutoresponderListUnwrapsTheArray(): void
    {
        $skrybe = $this->client(['{"autoresponders":[{"id":1}]}']);
        $this->assertSame([['id' => 1]], $skrybe->autoresponders->list('A1'));
        $this->assertSame(['list_id' => 'A1'], $this->sent());
    }

    // ------------------------------------------------ brands, sms and stats

    public function testBrandsListReadsTheNumberedPayload(): void
    {
        $skrybe = $this->client([self::phpNumberedPayload('brand', [['id' => '86', 'name' => 'Cafe One']])]);
        $this->assertSame([['id' => '86', 'name' => 'Cafe One']], $skrybe->brands->list());
    }

    public function testSmsSendPostsToMessageAndType(): void
    {
        $skrybe = $this->client(['{"id":1,"to":"+2348031234567","status":"sent","segments":1}']);
        $skrybe->sms->send('0803 123 4567', 'Your code is 1234', 'transactional');
        $this->assertSame('/api/sms/send.php', $this->path());
        $this->assertSame(['to' => '0803 123 4567', 'message' => 'Your code is 1234', 'type' => 'transactional'], $this->sent());
    }

    public function testSmsGetPostsTheId(): void
    {
        $skrybe = $this->client(['{"id":12,"status":"delivered"}']);
        $this->assertSame('delivered', $skrybe->sms->get(12)['status']);
        $this->assertSame(['id' => '12'], $this->sent());
    }

    public function testTotalEmailsSentIsAGetWithoutTheApiKey(): void
    {
        $skrybe = $this->client(['{"total_emails_sent":123}']);
        $this->assertSame(123, $skrybe->stats->totalEmailsSent());
        $this->assertSame('GET', $this->request()->getMethod());
        $this->assertSame('', $this->request()->getUri()->getQuery());
    }
}
