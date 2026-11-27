<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use MonitorHQ\EventType;
use MonitorHQ\Exception\WebhookException;
use MonitorHQ\Webhook;
use MonitorHQ\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_0123456789abcdef';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        $file = json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($file['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('vectors')]
    public function testSharedVectors(array $case): void
    {
        $this->assertSame(
            $case['expected'],
            Webhook::verify($case['body'], $case['header'], $case['secret'], $case['tolerance'], $case['now']),
            $case['name'],
        );
    }

    public function testVectorFileHasEnoughCases(): void
    {
        $file = json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(6, count($file['cases']));
        $results = array_unique(array_column($file['cases'], 'expected'));
        sort($results);
        $this->assertSame([false, true], $results);
    }

    public function testKnownAnswerComputedWithOpenssl(): void
    {
        // echo -n '1793000000.{"a":1}' | openssl dgst -sha256 -hmac 'whsec_test_0123456789abcdef'
        $expected = '25e9b1a648bdb07c29f18f094e5a91f2a9cd5007de352216230366973f8d9a16';

        $this->assertSame('t=1793000000,v1=' . $expected, Webhook::sign('{"a":1}', self::SECRET, 1793000000));
        $this->assertTrue(Webhook::verify('{"a":1}', 't=1793000000,v1=' . $expected, self::SECRET, 300, 1793000010));
    }

    public function testVerifyUsesCurrentTimeByDefault(): void
    {
        $header = Webhook::sign('{}', self::SECRET, time());
        $this->assertTrue(Webhook::verify('{}', $header, self::SECRET));
        $this->assertFalse(Webhook::verify('{}', Webhook::sign('{}', self::SECRET, time() - 1000), self::SECRET));
    }

    public function testEmptySecretIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhook::verify('{}', 't=1,v1=a', '');
    }

    public function testNegativeToleranceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhook::verify('{}', 't=1,v1=a', self::SECRET, -1);
    }

    public function testConstructEventForChangeDetected(): void
    {
        $body = (string) json_encode([
            'event' => 'change_detected',
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'entity_name' => 'EXAMPLE PTY LTD',
            'acn' => '005443292',
            'abn' => '80005443292',
            'entity_state' => ['entity_type' => 'APTY', 'acn_status' => 'REGD', 'abn_status' => 'Active', 'gst_registered' => true, 'gst_date' => '2000-07-01', 'business_names' => [], 'is_terminal' => false],
            'events' => [
                ['history_id' => 1042, 'title' => 'Status changed: Credit licence', 'description' => 'd', 'event_type' => 'register_status_change', 'effective_date' => '2026-10-28T00:00:00.000000Z', 'source' => 'register', 'initial' => false, 'metadata' => ['old_status' => 'Approved', 'status' => 'Suspended']],
                ['history_id' => 1043, 'title' => 'x', 'description' => 'y', 'event_type' => 'a_future_type', 'effective_date' => '2026-10-28T00:00:00.000000Z', 'source' => 'asic', 'initial' => false, 'metadata' => []],
            ],
            'detected_at' => '2026-10-27T20:20:14+00:00',
            'delivery_id' => 5531,
        ], JSON_UNESCAPED_SLASHES);
        $header = Webhook::sign($body, self::SECRET, 1793000000);

        $event = Webhook::constructEvent($body, $header, self::SECRET, 300, 1793000001);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame('change_detected', $event->type);
        $this->assertTrue($event->isChangeDetected());
        $this->assertFalse($event->isTest());
        $this->assertSame(5531, $event->deliveryId);
        $this->assertSame('005443292', $event->acn);
        $this->assertSame([1042, 1043], $event->historyIds());
        $this->assertSame(EventType::RegisterStatusChange, $event->events[0]->type);
        $this->assertSame('Suspended', $event->events[0]->metadata['status']);
        $this->assertSame(EventType::OtherChange, $event->events[1]->type);
        $this->assertSame('a_future_type', $event->events[1]->typeRaw);
        $this->assertFalse($event->events[0]->initial);
        $this->assertSame('APTY', $event->entityState?->entityType);
        $this->assertSame('2026-10-27T20:20:14+00:00', $event->detectedAt?->format('c'));
        $this->assertSame('EXAMPLE PTY LTD', $event->raw['entity_name']);
    }

    public function testConstructEventForTest(): void
    {
        $body = '{"event":"test","message":"This is a test delivery from MonitorHQ.","detected_at":"2026-10-27T20:20:14+00:00"}';
        $event = Webhook::constructEvent($body, Webhook::sign($body, self::SECRET, 1793000000), self::SECRET, 300, 1793000000);

        $this->assertTrue($event->isTest());
        $this->assertSame([], $event->events);
        $this->assertNull($event->deliveryId);
        $this->assertSame('This is a test delivery from MonitorHQ.', $event->message);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'malformed' => ['garbage', WebhookException::MALFORMED_HEADER];
        yield 'missing v1' => ['t=1793000000', WebhookException::MALFORMED_HEADER];
        yield 'wrong signature' => ['t=1793000000,v1=' . str_repeat('0', 64), WebhookException::NO_MATCHING_SIGNATURE];
        yield 'stale' => ['t=1700000000,v1=' . str_repeat('0', 64), WebhookException::TIMESTAMP_OUT_OF_TOLERANCE];
    }

    #[DataProvider('failures')]
    public function testConstructEventThrowsWithReason(string $header, string $reason): void
    {
        try {
            Webhook::constructEvent('{"event":"test"}', $header, self::SECRET, 300, 1793000000);
            $this->fail('expected exception');
        } catch (WebhookException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($reason, $e->errorCode);
        }
    }

    public function testConstructEventRejectsTamperedBody(): void
    {
        $header = Webhook::sign('{"event":"test"}', self::SECRET, 1793000000);
        $this->expectException(WebhookException::class);
        Webhook::constructEvent('{"event":"test" }', $header, self::SECRET, 300, 1793000000);
    }

    public function testConstructEventRejectsValidSignatureOverNonJson(): void
    {
        $header = Webhook::sign('nope', self::SECRET, 1793000000);
        try {
            Webhook::constructEvent('nope', $header, self::SECRET, 300, 1793000000);
            $this->fail('expected exception');
        } catch (WebhookException $e) {
            $this->assertSame(WebhookException::INVALID_PAYLOAD, $e->reason);
        }
    }

    public function testConstructEventRejectsPayloadWithoutEvent(): void
    {
        $header = Webhook::sign('{"a":1}', self::SECRET, 1793000000);
        $this->expectException(WebhookException::class);
        Webhook::constructEvent('{"a":1}', $header, self::SECRET, 300, 1793000000);
    }

    public function testUnicodeBodyIsSignedAsRawBytes(): void
    {
        $body = '{"event":"test","message":"café ✓"}' . "\xc3\xa9";
        $header = Webhook::sign($body, self::SECRET, 1793000000);
        $this->assertTrue(Webhook::verify($body, $header, self::SECRET, 300, 1793000000));
        $this->assertFalse(Webhook::verify($body . ' ', $header, self::SECRET, 300, 1793000000));
    }
}
