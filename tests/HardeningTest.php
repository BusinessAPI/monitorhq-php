<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use GuzzleHttp\Psr7\Response;
use MonitorHQ\Client;
use MonitorHQ\Config;
use MonitorHQ\Exception\MonitorHQException;
use MonitorHQ\Http\Transport;
use MonitorHQ\Model\HistoryItem;
use MonitorHQ\Webhook;
use MonitorHQ\WebhookEvent;

final class HardeningTest extends TestCase
{
    private const TOKEN = '7|aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789LEAK';
    private const SECRET = 'whsec_LEAKLEAKLEAK0123456789';

    private function secretClient(): Client
    {
        return new Client(self::TOKEN, ['http_client' => $this->http]);
    }

    public function testTokenNeverAppearsInDumps(): void
    {
        $client = $this->secretClient();
        $dumps = [
            'print_r client' => print_r($client, true),
            'print_r config' => print_r($client->config, true),
            'var_export' => var_export($client, true),
            'var_export config' => var_export($client->config, true),
        ];
        ob_start();
        var_dump($client);
        var_dump($client->config);
        $dumps['var_dump'] = (string) ob_get_clean();

        foreach ($dumps as $name => $dump) {
            $this->assertStringNotContainsString('LEAK', $dump, $name);
            $this->assertStringNotContainsString('aBcDeFgHiJk', $dump, $name);
        }
        $this->assertStringContainsString('[redacted]', print_r($client->config, true));
    }

    public function testTokenCannotBeSerialized(): void
    {
        $client = $this->secretClient();
        foreach ([$client, $client->config] as $object) {
            try {
                serialize($object);
                $this->fail('expected exception');
            } catch (\LogicException $e) {
                $this->assertStringNotContainsString('LEAK', $e->getMessage());
            }
        }
    }

    public function testTokenIsStillSentAsTheBearerCredential(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => []]));
        $this->secretClient()->account->get();
        $this->assertSame('Bearer ' . self::TOKEN, $this->http->last()->getHeaderLine('Authorization'));
        $this->assertFalse((new \ReflectionClass(Config::class))->hasProperty('apiKey') && (new \ReflectionProperty(Config::class, 'apiKey'))->isPublic());
    }

    public function testTokenIsHiddenFromStackTraces(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('#[SensitiveParameter] needs PHP 8.2.');
        }
        try {
            new Client(self::TOKEN, ['max_retries' => 99]);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('LEAK', $e->getTraceAsString());
        }
        try {
            Webhook::verify('{}', 't=1,v1=a', self::SECRET, -1);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('LEAK', $e->getTraceAsString());
        }
    }

    public function testReportRejectsHtmlCapturePage(): void
    {
        $this->http->push(new Response(200, ['Content-Type' => 'text/html'], '<html>Please log in to the wifi</html>'));
        try {
            $this->client()->monitorings->report('u');
            $this->fail('expected exception');
        } catch (MonitorHQException $e) {
            $this->assertStringNotContainsString('wifi', $e->getMessage());
            $this->assertSame('', $e->rawBody);
        }
    }

    public function testReportRejectsHtmlBodyWithPdfContentType(): void
    {
        $this->http->push(new Response(200, ['Content-Type' => 'application/pdf'], '<html>nope</html>'));
        $this->expectException(MonitorHQException::class);
        $this->client()->monitorings->report('u');
    }

    public function testReportRejectsEmptyBody(): void
    {
        $this->http->push(new Response(200, ['Content-Type' => 'application/pdf'], ''));
        $this->expectException(MonitorHQException::class);
        $this->client()->monitorings->report('u');
    }

    public function testReportRejectsOversizeBody(): void
    {
        $this->http->push(new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-' . str_repeat('a', Transport::MAX_REPORT_BYTES)));
        $this->expectException(MonitorHQException::class);
        $this->expectExceptionMessage('too large');
        $this->client()->monitorings->report('u');
    }

    public function testRedirectIsAnErrorNamingTheLocation(): void
    {
        $this->http->push(new Response(301, ['Location' => "https://elsewhere.example/api/v1/account"], ''));
        try {
            $this->client()->account->get();
            $this->fail('expected exception');
        } catch (MonitorHQException $e) {
            $this->assertSame(301, $e->statusCode);
            $this->assertStringContainsString('https://elsewhere.example/api/v1/account', $e->getMessage());
            $this->assertStringContainsString('base_url', $e->getMessage());
        }
        $this->assertCount(1, $this->http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'plain http remote' => ['http://monitorhq.com.au/api/v1'];
        yield 'userinfo' => ['https://user:pass@monitorhq.com.au/api/v1'];
        yield 'query' => ['https://monitorhq.com.au/api/v1?x=1'];
        yield 'fragment' => ['https://monitorhq.com.au/api/v1#x'];
        yield 'no host' => ['https:///api'];
        yield 'garbage' => ['not a url'];
        yield 'ftp' => ['ftp://monitorhq.com.au'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBaseUrls')]
    public function testBaseUrlIsStrict(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config('YOUR_API_TOKEN', $url);
    }

    public function testBaseUrlAllowsLocalhostOverHttp(): void
    {
        foreach (['http://localhost:8000/api/v1', 'http://127.0.0.1/api/v1', 'http://[::1]:8000/api/v1', 'https://monitorhq.ddev.site/api/v1'] as $url) {
            $this->assertSame($url, (new Config('YOUR_API_TOKEN', $url))->baseUrl);
        }
    }

    public function testMaxRetriesIsCapped(): void
    {
        $this->assertSame(5, (new Config('YOUR_API_TOKEN', null, 30.0, 5))->maxRetries);
        $this->expectException(\InvalidArgumentException::class);
        new Config('YOUR_API_TOKEN', null, 30.0, 6);
    }

    public function testMissingHistoryIdIsNullNotZero(): void
    {
        $this->assertNull(HistoryItem::fromArray(['type' => 'status_change'])->historyId);
        $event = WebhookEvent::fromArray(['event' => 'change_detected', 'events' => [['event_type' => 'status_change'], ['history_id' => 9, 'event_type' => 'status_change']]]);
        $this->assertNull($event->events[0]->historyId);
        $this->assertSame([9], $event->historyIds());
    }

    public function testWebhookHeaderOnlyStripsSpaces(): void
    {
        $sig = Webhook::sign('{}', self::SECRET, 1793000000);
        $this->assertTrue(Webhook::verify('{}', $sig, self::SECRET, 300, 1793000000));
        $this->assertTrue(Webhook::verify('{}', str_replace(',', ' , ', $sig), self::SECRET, 300, 1793000000));
        foreach (["\t", "\n", "\0", "\r"] as $ws) {
            $this->assertFalse(Webhook::verify('{}', $ws . $sig, self::SECRET, 300, 1793000000), 'leading ' . bin2hex($ws));
            $this->assertFalse(Webhook::verify('{}', str_replace(',v1', $ws . ',v1', $sig), self::SECRET, 300, 1793000000), 'trailing t ' . bin2hex($ws));
            $this->assertFalse(Webhook::verify('{}', $sig . $ws, self::SECRET, 300, 1793000000), 'trailing ' . bin2hex($ws));
        }
    }

    public function testWebhookTimestampRejectsLeadingZero(): void
    {
        $hmac = hash_hmac('sha256', '01793000000.{}', self::SECRET);
        $this->assertFalse(Webhook::verify('{}', "t=01793000000,v1=$hmac", self::SECRET, 300, 1793000000));
    }

    public function testReportFilenameControlCharactersAreStripped(): void
    {
        $this->http->push(new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename*=UTF-8''evil%00name%0d%0a.pdf"], '%PDF-1.7'));
        $name = $this->client()->monitorings->report('u')->filename;
        $this->assertSame('evilname.pdf', $name);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', (string) $name);
    }

    public function testEveryWebhookVectorIsRunByTheSuite(): void
    {
        // sdks/typescript carries a byte-identical copy of this file.
        $file = json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(6, count($file['cases']));
        $failures = [];
        foreach ($file['cases'] as $case) {
            if (Webhook::verify($case['body'], $case['header'], $case['secret'], $case['tolerance'], $case['now']) !== $case['expected']) {
                $failures[] = $case['name'];
            }
        }
        $this->assertSame([], $failures);
    }
}
