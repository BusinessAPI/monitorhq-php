<?php

declare(strict_types=1);

namespace MonitorHQ\Service;

use MonitorHQ\EventType;
use MonitorHQ\Model\BulkAddResult;
use MonitorHQ\Model\HistoryItem;
use MonitorHQ\Model\Monitoring;
use MonitorHQ\Model\MonitoringSnapshot;
use MonitorHQ\Model\Page;
use MonitorHQ\Model\Report;
use MonitorHQ\RiskLevel;

final class Monitorings extends Service
{
    private const LIST_FILTERS = ['page', 'per_page', 'risk_level', 'is_active', 'acn', 'abn'];
    private const HISTORY_FILTERS = ['page', 'per_page', 'event_type', 'detected_since'];

    /**
     * @param array{page?: int, per_page?: int, risk_level?: RiskLevel|string, is_active?: bool, acn?: string, abn?: string} $filters
     * @return Page<Monitoring>
     */
    public function list(array $filters = []): Page
    {
        $query = $this->filters($filters, self::LIST_FILTERS);

        return Page::fromArray($this->transport->request('GET', 'monitorings', $query), Monitoring::fromArray(...));
    }

    /**
     * @param array{page?: int, per_page?: int, risk_level?: RiskLevel|string, is_active?: bool, acn?: string, abn?: string} $filters
     * @return \Generator<int, Monitoring>
     */
    public function listAll(array $filters = []): \Generator
    {
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        do {
            $result = $this->list([...$filters, 'page' => $page]);
            foreach ($result->items as $item) {
                yield $item;
            }
            $page++;
        } while ($result->hasNextPage() && $result->items !== []);
    }

    /** @param array{acn?: string|null, abn?: string|null, risk_level: RiskLevel|string, notification_emails?: list<string>} $data */
    public function add(array $data): Monitoring
    {
        $body = $this->entityBody($data, ['acn', 'abn', 'risk_level', 'notification_emails']);

        return Monitoring::fromArray($this->unwrap($this->transport->request('POST', 'monitorings', [], $body)));
    }

    /** @param list<array{acn?: string|null, abn?: string|null, risk_level: RiskLevel|string}> $entities */
    public function bulkAdd(array $entities): BulkAddResult
    {
        if ($entities === []) {
            throw new \InvalidArgumentException('Provide at least one entity.');
        }
        if (count($entities) > 100) {
            throw new \InvalidArgumentException('A bulk add takes at most 100 entities per request.');
        }

        $rows = [];
        foreach ($entities as $entity) {
            $rows[] = $this->entityBody($entity, ['acn', 'abn', 'risk_level']);
        }

        return BulkAddResult::fromArray($this->transport->request('POST', 'monitorings/bulk', [], ['entities' => $rows]));
    }

    public function get(string $uuid): MonitoringSnapshot
    {
        return MonitoringSnapshot::fromArray($this->unwrap($this->transport->request('GET', 'monitorings/' . $this->seg($uuid))));
    }

    /**
     * @param array{page?: int, per_page?: int, event_type?: EventType|string, detected_since?: \DateTimeInterface|string} $filters
     * @return Page<HistoryItem>
     */
    public function history(string $uuid, array $filters = []): Page
    {
        $query = $this->filters($filters, self::HISTORY_FILTERS);

        return Page::fromArray(
            $this->transport->request('GET', 'monitorings/' . $this->seg($uuid) . '/history', $query),
            HistoryItem::fromArray(...),
        );
    }

    /**
     * @param array{page?: int, per_page?: int, event_type?: EventType|string, detected_since?: \DateTimeInterface|string} $filters
     * @return \Generator<int, HistoryItem>
     */
    public function historyAll(string $uuid, array $filters = []): \Generator
    {
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        do {
            $result = $this->history($uuid, [...$filters, 'page' => $page]);
            foreach ($result->items as $item) {
                yield $item;
            }
            $page++;
        } while ($result->hasNextPage() && $result->items !== []);
    }

    public function update(string $uuid, RiskLevel|string $riskLevel): Monitoring
    {
        $body = ['risk_level' => $riskLevel instanceof RiskLevel ? $riskLevel->value : $riskLevel];

        return Monitoring::fromArray($this->unwrap($this->transport->request('PUT', 'monitorings/' . $this->seg($uuid), [], $body)));
    }

    public function stop(string $uuid): string
    {
        $body = $this->transport->request('DELETE', 'monitorings/' . $this->seg($uuid));

        return is_string($body['message'] ?? null) ? $body['message'] : '';
    }

    public function report(string $uuid, bool $includeRecent = true): Report
    {
        return $this->transport->download('monitorings/' . $this->seg($uuid) . '/report', ['include_recent' => $includeRecent]);
    }

    public function findByAcn(string $acn, bool $isActive = true): ?Monitoring
    {
        $page = $this->list(['acn' => preg_replace('/\s+/', '', $acn) ?? $acn, 'is_active' => $isActive, 'per_page' => 1]);

        return $page->items[0] ?? null;
    }

    public function findByAbn(string $abn, bool $isActive = true): ?Monitoring
    {
        $page = $this->list(['abn' => preg_replace('/\s+/', '', $abn) ?? $abn, 'is_active' => $isActive, 'per_page' => 1]);

        return $page->items[0] ?? null;
    }

    /**
     * @param array<mixed> $entity
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function entityBody(array $entity, array $allowed): array
    {
        $unknown = array_diff(array_map('strval', array_keys($entity)), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown field(s): ' . implode(', ', $unknown) . '.');
        }
        if (($entity['acn'] ?? null) === null && ($entity['abn'] ?? null) === null) {
            throw new \InvalidArgumentException('Either acn or abn is required.');
        }
        $risk = $entity['risk_level'] ?? null;
        if ($risk === null) {
            throw new \InvalidArgumentException('risk_level is required.');
        }

        $body = [];
        foreach ($entity as $key => $value) {
            if ($value === null) {
                continue;
            }
            $body[(string) $key] = $value instanceof RiskLevel ? $value->value : $value;
        }

        return $body;
    }
}
