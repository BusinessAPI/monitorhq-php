<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use MonitorHQ\Exception\ConnectionException;
use MonitorHQ\Exception\RateLimitException;
use MonitorHQ\Exception\ServerException;

final class RetryTest extends TestCase
{
    public function testGetRetriesServerErrorsThenSucceeds(): void
    {
        $this->http->push(
            FakeHttpClient::json(503, ['message' => 'down']),
            FakeHttpClient::json(500, ['message' => 'oops']),
            FakeHttpClient::json(200, ['name' => 'A']),
        );
        $account = $this->client()->account->get();

        $this->assertSame('A', $account->name);
        $this->assertCount(3, $this->http->requests);
        $this->assertCount(2, $this->sleeps);
    }

    public function testGetGivesUpAfterMaxRetries(): void
    {
        $this->http->push(FakeHttpClient::json(500, []), FakeHttpClient::json(500, []), FakeHttpClient::json(500, []), FakeHttpClient::json(200, []));
        try {
            $this->client(2)->account->get();
            $this->fail('expected exception');
        } catch (ServerException) {
            $this->assertCount(3, $this->http->requests, 'one attempt plus two retries');
        }
    }

    public function testMaxRetriesZeroNeverRetries(): void
    {
        $this->http->push(FakeHttpClient::json(500, []), FakeHttpClient::json(200, []));
        try {
            $this->client(0)->account->get();
            $this->fail('expected exception');
        } catch (ServerException) {
            $this->assertCount(1, $this->http->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function testGetRetriesNetworkErrors(): void
    {
        $this->http->push(new FakeNetworkException('reset'), FakeHttpClient::json(200, ['name' => 'A']));
        $this->assertSame('A', $this->client()->account->get()->name);
        $this->assertCount(2, $this->http->requests);
    }

    public function testNetworkErrorsExhaustRetries(): void
    {
        $this->http->push(new FakeNetworkException('1'), new FakeNetworkException('2'), new FakeNetworkException('3'));
        $this->expectException(ConnectionException::class);
        try {
            $this->client(2)->account->get();
        } finally {
            $this->assertCount(3, $this->http->requests);
        }
    }

    public function testDeleteRetriesServerErrors(): void
    {
        $this->http->push(FakeHttpClient::json(503, []), FakeHttpClient::json(200, ['message' => 'Monitoring stopped successfully']));
        $this->assertSame('Monitoring stopped successfully', $this->client()->monitorings->stop('u-1'));
        $this->assertCount(2, $this->http->requests);
        $this->assertSame('DELETE', $this->http->last()->getMethod());
    }

    public function testPutIsNeverRetriedOnServerErrorOrNetworkFailure(): void
    {
        $this->http->push(FakeHttpClient::json(500, []), FakeHttpClient::json(200, []));
        try {
            $this->client()->monitorings->update('u-1', 'low');
            $this->fail('expected exception');
        } catch (ServerException) {
            $this->assertCount(1, $this->http->requests);
        }

        $this->setUp();
        $this->http->push(new FakeNetworkException('timeout'), FakeHttpClient::json(200, []));
        $this->expectException(ConnectionException::class);
        $this->client()->monitorings->update('u-1', 'low');
    }

    public function testPostIsNeverRetriedOnServerError(): void
    {
        $this->http->push(FakeHttpClient::json(503, []), FakeHttpClient::json(200, ['valid' => true]));
        try {
            $this->client()->identifiers->check('123456789');
            $this->fail('expected exception');
        } catch (ServerException) {
            $this->assertCount(1, $this->http->requests);
        }
    }

    public function testRateLimitIsRetriedForPostHonouringRetryAfter(): void
    {
        $this->http->push(
            FakeHttpClient::json(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '7']),
            FakeHttpClient::json(201, ['data' => ['uuid' => 'u-1']]),
        );
        $m = $this->client()->monitorings->add(['acn' => '123456789', 'risk_level' => 'high']);

        $this->assertSame('u-1', $m->uuid);
        $this->assertSame([7.0], $this->sleeps);
        $this->assertCount(2, $this->http->requests);
        $this->assertSame((string) $this->http->requests[0]->getBody(), (string) $this->http->requests[1]->getBody(), 'the body is resent');
    }

    public function testRetryAfterIsCapped(): void
    {
        $this->http->push(FakeHttpClient::json(429, [], ['Retry-After' => '3600']), FakeHttpClient::json(200, []));
        $this->client()->account->get();
        $this->assertSame([60.0], $this->sleeps);
    }

    public function testRateLimitWithoutRetryAfterUsesBackoff(): void
    {
        $this->http->push(FakeHttpClient::json(429, []), FakeHttpClient::json(200, []));
        $this->client()->account->get();
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(0.25, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.5, $this->sleeps[0]);
    }

    public function testRateLimitExhaustedThrowsAfterRetries(): void
    {
        $this->http->push(
            FakeHttpClient::json(429, [], ['Retry-After' => '1']),
            FakeHttpClient::json(429, [], ['Retry-After' => '2']),
            FakeHttpClient::json(429, [], ['Retry-After' => '3']),
        );
        try {
            $this->client(2)->account->get();
            $this->fail('expected exception');
        } catch (RateLimitException $e) {
            $this->assertSame(3, $e->retryAfterSeconds);
            $this->assertCount(3, $this->http->requests);
            $this->assertSame([1.0, 2.0], $this->sleeps);
        }
    }

    public function testBackoffGrowsAndStaysBounded(): void
    {
        $this->http->push(...array_fill(0, 5, FakeHttpClient::json(500, [])), ...[FakeHttpClient::json(200, [])]);
        $this->client(5)->account->get();

        $this->assertCount(5, $this->sleeps);
        $bases = [0.5, 1.0, 2.0, 4.0, 8.0];
        foreach ($this->sleeps as $i => $s) {
            $this->assertGreaterThanOrEqual($bases[$i] / 2, $s);
            $this->assertLessThanOrEqual($bases[$i], $s);
        }
    }

    public function testRetryAfterHttpDateIsIgnoredSafely(): void
    {
        $this->http->push(FakeHttpClient::json(429, [], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']), FakeHttpClient::json(200, []));
        $this->client()->account->get();
        $this->assertLessThanOrEqual(0.5, $this->sleeps[0]);
    }
}
