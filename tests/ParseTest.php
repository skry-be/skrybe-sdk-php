<?php

namespace Skrybe\Tests;

use Skrybe\Exception\ApiException;
use Skrybe\Internal\Parse;

class ParseTest extends TestCase
{
    public function testReadsAWellFormedPayload(): void
    {
        $body = self::phpNumberedPayload('list', [
            ['id' => 'AbC892763xy', 'name' => 'Newsletter'],
            ['id' => 'ZzZ763892qq', 'name' => 'Announcements'],
        ]);

        $this->assertSame([
            ['id' => 'AbC892763xy', 'name' => 'Newsletter'],
            ['id' => 'ZzZ763892qq', 'name' => 'Announcements'],
        ], Parse::numberedObject($body, 'list'));
    }

    public function testReadsTheBrandPayload(): void
    {
        $body = self::phpNumberedPayload('brand', [['id' => '86', 'name' => 'Cafe One']]);
        $this->assertSame([['id' => '86', 'name' => 'Cafe One']], Parse::numberedObject($body, 'brand'));
    }

    public function testEmptyBodyIsEmpty(): void
    {
        $this->assertSame([], Parse::numberedObject('', 'list'));
        $this->assertSame([], Parse::numberedObject("   \n ", 'list'));
    }

    public function testIgnoresKeysThatAreNotThePrefix(): void
    {
        $body = '{"list1": {"id": "a", "name": "Keep"}, "total": {"id": "x", "name": "Drop"}}';
        $this->assertSame([['id' => 'a', 'name' => 'Keep']], Parse::numberedObject($body, 'list'));
    }

    public function testOrdersByNumericSuffix(): void
    {
        $body = '{"list1": {"id": "a", "name": "One"}, "list10": {"id": "j", "name": "Ten"}, "list2": {"id": "b", "name": "Two"}}';
        $this->assertSame(['One', 'Two', 'Ten'], array_column(Parse::numberedObject($body, 'list'), 'name'));
    }

    /**
     * get-lists.php on older installs interpolates the name straight into the
     * string it echoes, so awkward names reach us verbatim.
     */
    public static function awkwardNames(): array
    {
        return [
            'quoted word' => ['Q4 "Big" Push'],
            'ends in a quote' => ['Say "hi"'],
            'brace' => ['ends}'],
            'comma' => ['Lagos, Abuja'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardNames')]
    public function testRecoversNamesTheServerDoesNotEscape(string $name): void
    {
        $rows = [
            ['id' => 'AAA111', 'name' => 'Before'],
            ['id' => 'BBB222', 'name' => $name],
            ['id' => 'CCC333', 'name' => 'After'],
        ];
        $this->assertSame($rows, Parse::numberedObject(self::phpNumberedPayload('list', $rows), 'list'));
    }

    public function testRecoversTheLastEntryWhenItsNameEndsInAQuote(): void
    {
        $rows = [['id' => 'AAA111', 'name' => 'trailing "quote"']];
        $this->assertSame($rows, Parse::numberedObject(self::phpNumberedPayload('list', $rows), 'list'));
    }

    public function testKeepsIdsIntactWhenANameForgesASecondNameKey(): void
    {
        // Valid JSON with a duplicate key, so the fallback never runs and the
        // last name wins. Cosmetic only: the ids are unaffected.
        $rows = Parse::numberedObject(self::phpNumberedPayload('list', [
            ['id' => 'AAA111', 'name' => 'Before'],
            ['id' => 'BBB222', 'name' => 'x", "name": "injected'],
            ['id' => 'CCC333', 'name' => 'After'],
        ]), 'list');
        $this->assertSame(['AAA111', 'BBB222', 'CCC333'], array_column($rows, 'id'));
        $this->assertSame('injected', $rows[1]['name']);
    }

    public function testThrowsWithTheBodyAttachedWhenNothingIsRecognisable(): void
    {
        $body = "<br />\n<b>Warning</b>: mysqli_query(): MySQL server has gone away";
        try {
            Parse::numberedObject($body, 'list');
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame('malformed_response', $e->getErrorCode());
            $this->assertSame($body, $e->getRaw());
            $this->assertStringContainsString('MySQL server has gone away', $e->getHint());
        }
    }

    public function testCollapsesAMultiLineBodyIntoASingleLineHint(): void
    {
        try {
            Parse::numberedObject("one\n\n  two\n\tthree", 'list');
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertSame('The server sent: one two three', $e->getHint());
        }
    }

    public function testTruncatesAVeryLongBodyInTheHintButKeepsItWhole(): void
    {
        $body = 'x' . str_repeat('y', 5000);
        try {
            Parse::numberedObject($body, 'list');
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            $this->assertLessThan(300, strlen($e->getHint()));
            $this->assertStringEndsWith('…', $e->getHint());
            $this->assertSame($body, $e->getRaw());
        }
    }

    public function testJsonObjectRejectsAJsonArray(): void
    {
        $this->expectException(ApiException::class);
        Parse::jsonObject('[1,2]', 'thing');
    }

    public function testNumberedRecordsKeepsRowsWholeInOrder(): void
    {
        $body = '{"campaign2": {"id": 2, "title": "B"}, "campaign1": {"id": 1, "title": "A"}, "total": 2}';
        $this->assertSame([['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']], Parse::numberedRecords($body, 'campaign'));
    }
}
