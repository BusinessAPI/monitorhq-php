<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use MonitorHQ\EventType;
use MonitorHQ\RiskLevel;

final class ServicesTest extends TestCase
{
    /** @return array<mixed> */
    private function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testRequestsCarryAuthAndUserAgent(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['name' => 'A', 'email' => 'a@example.com', 'account_type' => 'admin']));
        $this->client()->account->get();

        $req = $this->http->last();
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('https://monitorhq.com.au/api/v1/account', (string) $req->getUri());
        $this->assertSame('Bearer YOUR_API_TOKEN', $req->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $req->getHeaderLine('Accept'));
        $this->assertStringStartsWith('monitorhq-php/', $req->getHeaderLine('User-Agent'));
    }

    public function testCustomBaseUrl(): void
    {
        $client = new \MonitorHQ\Client('YOUR_API_TOKEN', ['base_url' => 'https://example.test/api/v1/', 'http_client' => $this->http]);
        $this->http->push(FakeHttpClient::json(200, []));
        $client->account->get();

        $this->assertSame('https://example.test/api/v1/account', (string) $this->http->last()->getUri());
    }

    public function testAccountGet(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => [
            'name' => 'John Doe',
            'email' => 'admin@example.com',
            'account_type' => 'admin',
            'current_team' => ['name' => "John's Team", 'personal_team' => true, 'is_parent_team' => true],
            'owned_teams' => [['name' => "John's Team", 'personal_team' => true, 'is_parent_team' => true]],
            'member_of_teams' => [['id' => 2, 'name' => 'Another Team', 'role' => 'editor']],
        ]]));
        $account = $this->client()->account->get();

        $this->assertSame('John Doe', $account->name);
        $this->assertSame('admin', $account->accountType);
        $this->assertTrue($account->currentTeam['personal_team'] ?? null);
        $this->assertCount(1, $account->ownedTeams);
        $this->assertSame('editor', $account->memberOfTeams[0]['role']);
        $this->assertSame('admin@example.com', $account->raw['email']);
    }

    public function testSubscriptionGet(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => [
            'has_subscription' => true,
            'subscription' => ['plan' => 'Professional', 'status' => 'active', 'subscribed_at' => '2026-01-15T10:00:00+00:00', 'expires_at' => null, 'max_entities' => 50, 'max_high_risk_entities' => 10],
            'usage' => ['total' => 25, 'high' => 5, 'standard' => 15, 'low' => 5],
            'remaining' => ['slots' => 25, 'high_risk_slots' => 5],
        ]]));
        $sub = $this->client()->subscription->get();

        $this->assertSame('/api/v1/subscription', $this->http->last()->getUri()->getPath());
        $this->assertTrue($sub->hasSubscription);
        $this->assertSame('Professional', $sub->plan);
        $this->assertSame(50, $sub->maxEntities);
        $this->assertSame(25, $sub->usageTotal);
        $this->assertSame(5, $sub->remainingHighRiskSlots);
        $this->assertNull($sub->expiresAt);
    }

    public function testSubscriptionWithoutPlan(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['has_subscription' => false, 'message' => 'No active subscription found']));
        $sub = $this->client()->subscription->get();

        $this->assertFalse($sub->hasSubscription);
        $this->assertSame('No active subscription found', $sub->message);
        $this->assertNull($sub->plan);
    }

    public function testEnvelopesMatchWhatTheApiReallySends(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => ['name' => 'N', 'email' => 'e@example.com', 'account_type' => 'admin']]));
        $this->assertSame('N', $this->client()->account->get()->name);

        $this->http->push(FakeHttpClient::json(200, $this->fixture('snapshot')));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $this->client()->monitorings->get('u')->uuid);

        $this->http->push(FakeHttpClient::json(200, ['message' => 'Monitoring updated successfully', 'data' => $this->fixture('monitoring')]));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $this->client()->monitorings->update('u', 'high')->uuid);
        $this->http->push(FakeHttpClient::json(200, ['valid' => true, 'type' => 'abn', 'formatted' => 'x', 'number' => '12345678901']));
        $this->assertSame('abn', $this->client()->identifiers->check('12345678901')->type);
    }

    public function testUnwrappedBodiesStillWork(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['name' => 'Flat', 'email' => 'f@example.com']));
        $this->assertSame('Flat', $this->client()->account->get()->name);
    }

    public function testIdentifierCheckValid(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['valid' => true, 'type' => 'acn', 'formatted' => '123 456 789', 'number' => '123456789']));
        $check = $this->client()->identifiers->check('123 456 789');

        $req = $this->http->last();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v1/identifier/check', $req->getUri()->getPath());
        $this->assertSame('application/json', $req->getHeaderLine('Content-Type'));
        $this->assertSame(['identifier' => '123 456 789'], $this->http->lastJson());
        $this->assertTrue($check->valid);
        $this->assertSame('acn', $check->type);
        $this->assertSame('123456789', $check->number);
    }

    public function testIdentifierCheckInvalidIsAnAnswerNotAnException(): void
    {
        $this->http->push(FakeHttpClient::json(422, ['valid' => false, 'message' => 'Invalid identifier.', 'errors' => ['The identifier is not a valid ACN/ARBN.', 'The identifier is not a valid ABN.']]));
        $check = $this->client()->identifiers->check('123');

        $this->assertFalse($check->valid);
        $this->assertSame('Invalid identifier.', $check->message);
        $this->assertCount(2, $check->errors);
    }

    public function testMonitoringsListSendsFiltersAndParsesRows(): void
    {
        $this->http->push(FakeHttpClient::json(200, [
            'data' => [$this->fixture('monitoring')],
            'links' => ['first' => 'x', 'last' => null, 'prev' => null, 'next' => 'https://monitorhq.com.au/api/v1/monitorings?page=2'],
            'meta' => ['current_page' => 1, 'per_page' => 15, 'from' => 1, 'to' => 1],
        ]));
        $page = $this->client()->monitorings->list(['risk_level' => RiskLevel::High, 'is_active' => false, 'per_page' => 15, 'acn' => '123456789']);

        $query = [];
        parse_str($this->http->last()->getUri()->getQuery(), $query);
        $this->assertSame(['risk_level' => 'high', 'is_active' => 'false', 'per_page' => '15', 'acn' => '123456789'], $query);
        $this->assertTrue($page->hasNextPage());
        $this->assertSame(1, $page->currentPage);
        $this->assertCount(1, $page->items);

        $m = $page->items[0];
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $m->uuid);
        $this->assertSame(RiskLevel::High, $m->riskLevel);
        $this->assertSame('APTY', $m->entityType);
        $this->assertSame(1, $m->health->abnConsecutiveFailures);
        $this->assertSame('2026-01-16T03:12:00+00:00', $m->health->lastCheckedAt?->format('c'));
        $this->assertNotNull($m->entityState);
        $this->assertTrue($m->entityState->gstRegistered);
        $this->assertFalse($m->entityState->isTerminal);
        $this->assertSame('Example Pty Ltd', $m->raw['entity_name']);
    }

    public function testUnknownFilterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->monitorings->list(['risk' => 'high']);
    }

    public function testListAllWalksEveryPage(): void
    {
        $row = $this->fixture('monitoring');
        $page = static fn (int $n, bool $more): array => [
            'data' => [array_merge($row, ['uuid' => "uuid-$n"])],
            'links' => ['first' => 'x', 'last' => null, 'prev' => null, 'next' => $more ? 'next-url' : null],
            'meta' => ['current_page' => $n, 'per_page' => 1],
        ];
        $this->http->push(
            FakeHttpClient::json(200, $page(1, true)),
            FakeHttpClient::json(200, $page(2, true)),
            FakeHttpClient::json(200, $page(3, false)),
        );

        $uuids = [];
        foreach ($this->client()->monitorings->listAll(['per_page' => 1]) as $item) {
            $uuids[] = $item->uuid;
        }

        $this->assertSame(['uuid-1', 'uuid-2', 'uuid-3'], $uuids);
        $this->assertCount(3, $this->http->requests);
        $pages = array_map(static function ($r): string {
            parse_str($r->getUri()->getQuery(), $q);

            return (string) $q['page'];
        }, $this->http->requests);
        $this->assertSame(['1', '2', '3'], $pages);
    }

    public function testListAllStopsOnAnEmptyPageEvenIfNextIsSet(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => [], 'links' => ['next' => 'loop'], 'meta' => ['current_page' => 1]]));
        $this->assertSame([], iterator_to_array($this->client()->monitorings->listAll(), false));
        $this->assertCount(1, $this->http->requests);
    }

    public function testListAllIsLazy(): void
    {
        $gen = $this->client()->monitorings->listAll();
        $this->assertCount(0, $this->http->requests);
        $this->assertInstanceOf(\Generator::class, $gen);
    }

    public function testFindByAcnAndAbn(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => [$this->fixture('monitoring')], 'links' => [], 'meta' => []]));
        $found = $this->client()->monitorings->findByAcn('123 456 789');
        $query = [];
        parse_str($this->http->last()->getUri()->getQuery(), $query);
        $this->assertSame('123456789', $query['acn']);
        $this->assertSame('true', $query['is_active']);
        $this->assertNotNull($found);
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $found->uuid);

        $this->http->push(FakeHttpClient::json(200, ['data' => [], 'links' => [], 'meta' => []]));
        $this->assertNull($this->client()->monitorings->findByAbn('12 345 678 901'));
        parse_str($this->http->last()->getUri()->getQuery(), $query);
        $this->assertSame('12345678901', $query['abn']);
    }

    public function testAdd(): void
    {
        $this->http->push(FakeHttpClient::json(201, ['message' => 'Monitoring added successfully', 'data' => $this->fixture('monitoring')]));
        $m = $this->client()->monitorings->add(['acn' => '123456789', 'risk_level' => RiskLevel::Standard, 'notification_emails' => ['ops@example.com']]);

        $req = $this->http->last();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v1/monitorings', $req->getUri()->getPath());
        $this->assertSame(['acn' => '123456789', 'risk_level' => 'standard', 'notification_emails' => ['ops@example.com']], $this->http->lastJson());
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $m->uuid);
    }

    public function testAddByAbnOmitsNullAcn(): void
    {
        $this->http->push(FakeHttpClient::json(201, ['message' => 'Monitoring added successfully', 'data' => $this->fixture('monitoring')]));
        $this->client()->monitorings->add(['acn' => null, 'abn' => '12345678901', 'risk_level' => 'low']);

        $this->assertSame(['abn' => '12345678901', 'risk_level' => 'low'], $this->http->lastJson());
    }

    public function testAddRequiresAnIdentifierAndRiskLevel(): void
    {
        $client = $this->client();
        try {
            $client->monitorings->add(['risk_level' => 'high']);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException) {
            $this->assertCount(0, $this->http->requests);
        }
        $this->expectException(\InvalidArgumentException::class);
        $client->monitorings->add(['acn' => '123456789']);
    }

    public function testBulkAdd(): void
    {
        $this->http->push(FakeHttpClient::json(200, [
            'message' => 'Bulk import processed',
            'summary' => ['total' => 2, 'succeeded' => 1, 'failed' => 1],
            'results' => [
                ['index' => 0, 'success' => true, 'uuid' => 'u-1', 'error' => null],
                ['index' => 1, 'success' => false, 'uuid' => null, 'error' => 'This entity is already in your portfolio'],
            ],
        ]));
        $result = $this->client()->monitorings->bulkAdd([
            ['acn' => '123456789', 'risk_level' => RiskLevel::Standard],
            ['abn' => '12345678901', 'risk_level' => 'high'],
        ]);

        $req = $this->http->last();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v1/monitorings/bulk', $req->getUri()->getPath());
        $this->assertSame(['entities' => [['acn' => '123456789', 'risk_level' => 'standard'], ['abn' => '12345678901', 'risk_level' => 'high']]], $this->http->lastJson());
        $this->assertSame(2, $result->total);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame('u-1', $result->results[0]->uuid);
        $this->assertCount(1, $result->failures());
        $this->assertSame(1, $result->failures()[0]->index);
    }

    public function testBulkAddLimits(): void
    {
        $client = $this->client();
        $rows = array_fill(0, 101, ['acn' => '123456789', 'risk_level' => 'low']);
        foreach ([[], $rows] as $bad) {
            try {
                $client->monitorings->bulkAdd($bad);
                $this->fail('expected exception');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(0, $this->http->requests);
    }

    public function testGetSnapshot(): void
    {
        $this->http->push(FakeHttpClient::json(200, $this->fixture('snapshot')));
        $s = $this->client()->monitorings->get('123e4567-e89b-12d3-a456-426614174000');

        $this->assertSame('/api/v1/monitorings/123e4567-e89b-12d3-a456-426614174000', $this->http->last()->getUri()->getPath());
        $this->assertTrue($s->isLive());
        $this->assertSame(RiskLevel::High, $s->riskLevel);
        $this->assertSame('VIC', $s->entityDetails['state']);
        $this->assertSame('Video HQ', $s->tradeMarks[0]['words'] ?? null);
        $this->assertCount(1, $s->registrations ?? []);
        $this->assertSame([], $s->insolvencyNotices);
        $this->assertTrue($s->ppsr['monitored'] ?? false);
        $this->assertSame('2026-10-28T05:00:00+00:00', $s->registrationsUpdatedAt?->format('c'));
        $this->assertCount(1, $s->recentHistory);
        $this->assertSame(EventType::AbnRegistration, $s->recentHistory[0]->type);
        $this->assertTrue($s->recentHistory[0]->initial);
        $this->assertArrayHasKey('ppsr', $s->raw);
    }

    public function testStoppedSnapshotHasNullSections(): void
    {
        $this->http->push(FakeHttpClient::json(200, $this->fixture('stopped-snapshot')));
        $s = $this->client()->monitorings->get('u');

        $this->assertFalse($s->isLive());
        $this->assertNull($s->tradeMarks);
        $this->assertNull($s->registrations);
        $this->assertNull($s->insolvencyNotices);
        $this->assertNull($s->ppsr);
        $this->assertNull($s->health->lastCheckedAt);
        $this->assertNull($s->health->consecutiveFailures);
        $this->assertSame([], $s->recentHistory);
    }

    public function testUuidIsEncodedAsOneSegment(): void
    {
        $client = $this->client();
        foreach (['../account', 'a/b', '', '..', 'a?b'] as $bad) {
            try {
                $client->monitorings->get($bad);
                $this->fail("expected exception for '$bad'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(0, $this->http->requests);

        $this->http->push(FakeHttpClient::json(200, ['data' => []]));
        $client->monitorings->get('a b');
        $this->assertSame('/api/v1/monitorings/a%20b', $this->http->last()->getUri()->getPath());
    }

    public function testHistoryEncodesDetectedSince(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => [], 'links' => [], 'meta' => []]));
        $this->client()->monitorings->history('u-1', ['detected_since' => '2026-10-12T10:00:00+10:00', 'event_type' => EventType::AbnRegistration, 'per_page' => 100]);

        $uri = $this->http->last()->getUri();
        $this->assertSame('/api/v1/monitorings/u-1/history', $uri->getPath());
        $this->assertStringContainsString('detected_since=2026-10-12T10%3A00%3A00%2B10%3A00', $uri->getQuery());
        $this->assertStringContainsString('event_type=abn_registration', $uri->getQuery());
        $this->assertStringNotContainsString('+', $uri->getQuery());
    }

    public function testHistoryAcceptsDateTimeAndSendsUtc(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['data' => [], 'links' => [], 'meta' => []]));
        $this->client()->monitorings->history('u-1', ['detected_since' => new \DateTimeImmutable('2026-10-12 10:00:00', new \DateTimeZone('Australia/Sydney'))]);

        $this->assertStringContainsString('detected_since=2026-10-11T23%3A00%3A00Z', $this->http->last()->getUri()->getQuery());
    }

    public function testHistoryParsesRowsAndMapsUnknownTypes(): void
    {
        $row = ['history_id' => 7, 'type' => 'brand_new_type', 'source' => 'asic', 'initial' => false, 'effective_date' => '2026-10-28T00:00:00Z', 'detected_at' => '2026-10-28T03:00:00+00:00', 'title' => 'T', 'description' => 'D', 'metadata' => ['gst_cancelled' => true]];
        $this->http->push(FakeHttpClient::json(200, ['data' => [$row], 'links' => ['next' => null], 'meta' => ['current_page' => 1]]));
        $page = $this->client()->monitorings->history('u-1');

        $this->assertFalse($page->hasNextPage());
        $item = $page->items[0];
        $this->assertSame(7, $item->historyId);
        $this->assertSame(EventType::OtherChange, $item->type);
        $this->assertSame('brand_new_type', $item->typeRaw);
        $this->assertTrue($item->metadata['gst_cancelled']);
        $this->assertFalse($item->initial);
    }

    public function testHistoryAllWalksPagesAndKeepsFilters(): void
    {
        $row = static fn (int $id): array => ['history_id' => $id, 'type' => 'status_change', 'initial' => false];
        $this->http->push(
            FakeHttpClient::json(200, ['data' => [$row(1), $row(2)], 'links' => ['next' => 'n'], 'meta' => ['current_page' => 1]]),
            FakeHttpClient::json(200, ['data' => [$row(3)], 'links' => ['next' => null], 'meta' => ['current_page' => 2]]),
        );

        $ids = [];
        foreach ($this->client()->monitorings->historyAll('u-1', ['detected_since' => '2026-10-12T00:00:00Z', 'per_page' => 2]) as $item) {
            $ids[] = $item->historyId;
        }

        $this->assertSame([1, 2, 3], $ids);
        foreach ($this->http->requests as $r) {
            $this->assertStringContainsString('detected_since=2026-10-12T00%3A00%3A00Z', $r->getUri()->getQuery());
        }
        $this->assertStringContainsString('page=2', $this->http->last()->getUri()->getQuery());
    }

    public function testUpdate(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['message' => 'Monitoring updated successfully', 'data' => array_merge($this->fixture('monitoring'), ['risk_level' => 'standard'])]));
        $m = $this->client()->monitorings->update('u-1', RiskLevel::Standard);

        $req = $this->http->last();
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/api/v1/monitorings/u-1', $req->getUri()->getPath());
        $this->assertSame(['risk_level' => 'standard'], $this->http->lastJson());
        $this->assertSame(RiskLevel::Standard, $m->riskLevel);
    }

    public function testStop(): void
    {
        $this->http->push(FakeHttpClient::json(200, ['message' => 'Monitoring stopped successfully']));
        $message = $this->client()->monitorings->stop('u-1');

        $req = $this->http->last();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/api/v1/monitorings/u-1', $req->getUri()->getPath());
        $this->assertSame('Monitoring stopped successfully', $message);
        $this->assertSame('', (string) $req->getBody());
    }

    public function testReportReturnsPdfAndFilename(): void
    {
        $pdf = "%PDF-1.7\n\x00\x01binary\n%%EOF";
        $this->http->push(new \GuzzleHttp\Psr7\Response(200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="entity-snapshot-example-pty-ltd-2026-10-28.pdf"',
        ], $pdf));
        $report = $this->client()->monitorings->report('u-1');

        $uri = $this->http->last()->getUri();
        $this->assertSame('/api/v1/monitorings/u-1/report', $uri->getPath());
        $this->assertSame('include_recent=true', $uri->getQuery());
        $this->assertStringContainsString('application/pdf', $this->http->last()->getHeaderLine('Accept'));
        $this->assertSame($pdf, $report->contents);
        $this->assertSame($pdf, (string) $report);
        $this->assertSame('entity-snapshot-example-pty-ltd-2026-10-28.pdf', $report->filename);
        $this->assertSame('application/pdf', $report->contentType);
    }

    public function testReportWithoutRecentAndNoFilename(): void
    {
        $this->http->push(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.7'));
        $report = $this->client()->monitorings->report('u-1', includeRecent: false);

        $this->assertSame('include_recent=false', $this->http->last()->getUri()->getQuery());
        $this->assertNull($report->filename);
    }

    public function testReportFilenameCannotEscapeADirectory(): void
    {
        $this->http->push(new \GuzzleHttp\Psr7\Response(200, ['Content-Disposition' => 'attachment; filename="../../etc/passwd"'], '%PDF-1.7'));
        $this->assertSame('passwd', $this->client()->monitorings->report('u-1')->filename);
    }

    public function testReportSave(): void
    {
        $this->http->push(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/pdf'], '%PDF-test'));
        $report = $this->client()->monitorings->report('u-1');
        $path = tempnam(sys_get_temp_dir(), 'mhq');
        $this->assertNotFalse($path);
        try {
            $this->assertSame(9, $report->save($path));
            $this->assertSame('%PDF-test', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testEventTypeCoversEveryDocumentedType(): void
    {
        $documented = [
            'company_registration', 'abn_registration', 'gst_registration', 'document_lodged', 'name_change', 'former_name_period',
            'business_name_added', 'business_name_removed', 'status_change', 'type_change', 'address_change', 'date_change',
            'review_date_change', 'other_change', 'ppsr_registration', 'insolvency_notice', 'trade_mark_filed',
            'trade_mark_status_change', 'trade_mark_owner_change', 'trade_mark_renewal_due', 'trade_mark_renewed',
            'register_added', 'register_status_change', 'register_removed', 'register_conditions_change',
        ];
        $this->assertSame($documented, array_map(static fn (EventType $c): string => $c->value, EventType::cases()));
        foreach ($documented as $value) {
            $this->assertSame($value, EventType::fromApi($value)->value);
        }
        $this->assertSame(EventType::OtherChange, EventType::fromApi('something_new'));
        $this->assertSame(EventType::OtherChange, EventType::fromApi(null));
        $this->assertSame(EventType::OtherChange, EventType::fromApi(''));
    }

    public function testOperationsJsonPointsAtRealMethods(): void
    {
        $ops = json_decode((string) file_get_contents(__DIR__ . '/../operations.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($ops);
        $this->assertIsArray($ops['operations']);
        $this->assertCount(11, $ops['operations']);
        foreach ($ops['operations'] as $route => $target) {
            $this->assertMatchesRegularExpression('#^(GET|POST|PUT|DELETE) /api/v1/#', (string) $route);
            [$file, $method] = explode('#', (string) $target);
            $source = (string) file_get_contents(__DIR__ . '/../' . $file);
            $this->assertSame(1, preg_match(str_replace('{method}', preg_quote($method, '/'), '/' . $ops['definition'] . '/'), $source), "$route -> $target");
        }
    }
}
