<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use GuzzleHttp\Psr7\Response;
use MonitorHQ\Exception\AlreadyMonitoredException;
use MonitorHQ\Exception\AuthenticationException;
use MonitorHQ\Exception\CapacityExceededException;
use MonitorHQ\Exception\ConflictException;
use MonitorHQ\Exception\ConnectionException;
use MonitorHQ\Exception\MonitorHQException;
use MonitorHQ\Exception\NotFoundException;
use MonitorHQ\Exception\PermissionException;
use MonitorHQ\Exception\RateLimitException;
use MonitorHQ\Exception\ServerException;
use MonitorHQ\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorsTest extends TestCase
{
    public function testUnauthenticated(): void
    {
        $this->http->push(FakeHttpClient::json(401, ['message' => 'Unauthenticated.'], ['X-Request-Id' => 'req-123']));
        try {
            $this->client()->account->get();
            $this->fail('expected exception');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('Unauthenticated.', $e->getMessage());
            $this->assertSame('req-123', $e->requestId);
            $this->assertSame(['message' => 'Unauthenticated.'], $e->body);
            $this->assertStringContainsString('Unauthenticated', $e->rawBody);
            $this->assertInstanceOf(MonitorHQException::class, $e);
        }
        $this->assertCount(1, $this->http->requests, '401 is never retried');
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function permissionCases(): iterable
    {
        yield 'missing ability' => [403, 'Invalid ability provided.', PermissionException::class];
        yield 'api off' => [403, 'API access is not enabled for this subscription', PermissionException::class];
        yield 'owners only' => [403, 'Only team owners can stop monitorings', PermissionException::class];
    }

    #[DataProvider('permissionCases')]
    public function testForbidden(int $status, string $message, string $class): void
    {
        $this->http->push(FakeHttpClient::json($status, ['message' => $message]));
        $this->expectException($class);
        $this->expectExceptionMessage($message);
        $this->client()->monitorings->stop('u-1');
    }

    public function testNotFoundCarriesCode(): void
    {
        $this->http->push(FakeHttpClient::json(404, ['message' => 'Monitoring not found', 'code' => 'monitoring_not_found']));
        try {
            $this->client()->monitorings->get('u-1');
            $this->fail('expected exception');
        } catch (NotFoundException $e) {
            $this->assertSame('monitoring_not_found', $e->errorCode);
            $this->assertSame(404, $e->statusCode);
        }
    }

    public function testValidationErrorsKeepFieldMessagesAndStatus(): void
    {
        $this->http->push(FakeHttpClient::json(422, [
            'message' => 'The risk level must be high, standard, or low. (and 1 more error)',
            'errors' => ['risk_level' => ['Risk level must be high, standard, or low.'], 'acn' => ['The acn is not a valid ACN/ARBN.']],
        ]));
        try {
            $this->client()->monitorings->update('u-1', 'urgent');
            $this->fail('expected exception');
        } catch (ValidationException $e) {
            $this->assertNotInstanceOf(AlreadyMonitoredException::class, $e);
            $this->assertSame(422, $e->statusCode);
            $this->assertSame(['Risk level must be high, standard, or low.'], $e->fieldErrors['risk_level']);
            $this->assertSame(['The acn is not a valid ACN/ARBN.'], $e->fieldErrors['acn']);
            $this->assertSame([], $e->messages);
        }
    }

    public function testValidationWithoutErrorsMap(): void
    {
        $this->http->push(FakeHttpClient::json(422, ['message' => 'Either ACN or ABN is required']));
        try {
            $this->client()->monitorings->add(['acn' => '1', 'risk_level' => 'low']);
            $this->fail('expected exception');
        } catch (ValidationException $e) {
            $this->assertSame('Either ACN or ABN is required', $e->getMessage());
            $this->assertNull($e->errorCode);
            $this->assertSame([], $e->fieldErrors);
        }
    }

    public function testValidationWithListErrors(): void
    {
        $this->http->push(FakeHttpClient::json(422, ['message' => 'Bad', 'errors' => ['one', 'two']]));
        try {
            $this->client()->monitorings->add(['acn' => '1', 'risk_level' => 'low']);
            $this->fail('expected exception');
        } catch (ValidationException $e) {
            $this->assertSame(['one', 'two'], $e->messages);
            $this->assertSame([], $e->fieldErrors);
        }
    }

    public function testAlreadyMonitoredExposesUuid(): void
    {
        $this->http->push(FakeHttpClient::json(422, ['message' => 'This entity is already in your portfolio', 'code' => 'already_monitored', 'uuid' => 'existing-uuid']));
        try {
            $this->client()->monitorings->add(['acn' => '123456789', 'risk_level' => 'high']);
            $this->fail('expected exception');
        } catch (AlreadyMonitoredException $e) {
            $this->assertSame('existing-uuid', $e->uuid);
            $this->assertSame('already_monitored', $e->errorCode);
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertSame(422, $e->statusCode);
        }
    }

    public function testCapacityExceeded(): void
    {
        $this->http->push(FakeHttpClient::json(422, ['message' => 'Your plan limit is reached', 'code' => 'capacity_exceeded']));
        $this->expectException(CapacityExceededException::class);
        $this->client()->monitorings->add(['acn' => '123456789', 'risk_level' => 'high']);
    }

    public function testStoppedReportIsAConflict(): void
    {
        $this->http->push(FakeHttpClient::json(409, ['message' => 'This monitoring has been stopped', 'code' => 'monitoring_stopped']));
        try {
            $this->client()->monitorings->report('u-1');
            $this->fail('expected exception');
        } catch (ConflictException $e) {
            $this->assertSame('monitoring_stopped', $e->errorCode);
        }
    }

    public function testServerErrorOnPostIsNotRetried(): void
    {
        $this->http->push(FakeHttpClient::json(500, ['message' => 'Unable to add monitoring at this time.']), FakeHttpClient::json(201, ['data' => []]));
        try {
            $this->client()->monitorings->add(['acn' => '123456789', 'risk_level' => 'high']);
            $this->fail('expected exception');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->statusCode);
        }
        $this->assertCount(1, $this->http->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function testNonJsonErrorBody(): void
    {
        $this->http->push(new Response(502, [], '<html>Bad gateway</html>'), new Response(502, [], 'x'), new Response(502, [], 'x'));
        try {
            $this->client()->account->get();
            $this->fail('expected exception');
        } catch (ServerException $e) {
            $this->assertSame('The MonitorHQ API returned HTTP 502.', $e->getMessage());
            $this->assertNull($e->body);
            $this->assertSame('x', $e->rawBody);
        }
    }

    public function testUnmappedStatusIsBaseException(): void
    {
        $this->http->push(FakeHttpClient::json(418, ['message' => 'teapot']));
        try {
            $this->client()->account->get();
            $this->fail('expected exception');
        } catch (MonitorHQException $e) {
            $this->assertSame(MonitorHQException::class, $e::class);
            $this->assertSame(418, $e->statusCode);
        }
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $this->http->push(FakeHttpClient::json(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '42']));
        try {
            $this->client(0)->account->get();
            $this->fail('expected exception');
        } catch (RateLimitException $e) {
            $this->assertSame(42, $e->retryAfterSeconds);
            $this->assertSame(429, $e->statusCode);
        }
    }

    public function testRateLimitWithoutHeader(): void
    {
        $this->http->push(FakeHttpClient::json(429, ['message' => 'Too Many Attempts.']));
        try {
            $this->client(0)->account->get();
            $this->fail('expected exception');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfterSeconds);
        }
    }

    public function testConnectionFailureOnPostIsNotRetried(): void
    {
        $this->http->push(new FakeNetworkException('timed out'), FakeHttpClient::json(201, ['data' => []]));
        try {
            $this->client()->monitorings->add(['acn' => '123456789', 'risk_level' => 'high']);
            $this->fail('expected exception');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertInstanceOf(FakeNetworkException::class, $e->getPrevious());
        }
        $this->assertCount(1, $this->http->requests);
    }

    public function testInvalidJsonOnSuccess(): void
    {
        $this->http->push(new Response(200, [], 'not json'));
        $this->expectException(MonitorHQException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->client()->account->get();
    }

    public function testErrorMessageFromTheApiIsUsedVerbatim(): void
    {
        $this->http->push(FakeHttpClient::json(403, ['message' => 'This token is no longer scoped to a team you belong to']));
        $this->expectExceptionMessage('This token is no longer scoped to a team you belong to');
        $this->client()->account->get();
    }
}
