<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

final class BulkAddResult
{
    /**
     * @param list<BulkAddRow> $results
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly ?string $message,
        public readonly int $total,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly array $results,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $summary = Arr::map($data, 'summary') ?? [];
        $rows = [];
        foreach (Arr::rows($data, 'results') ?? [] as $row) {
            $rows[] = new BulkAddRow(
                Arr::int($row, 'index') ?? 0,
                Arr::bool($row, 'success') ?? false,
                Arr::string($row, 'uuid'),
                Arr::string($row, 'error'),
            );
        }

        return new self(
            Arr::string($data, 'message'),
            Arr::int($summary, 'total') ?? count($rows),
            Arr::int($summary, 'succeeded') ?? 0,
            Arr::int($summary, 'failed') ?? 0,
            $rows,
            $data,
        );
    }

    /** @return list<BulkAddRow> */
    public function failures(): array
    {
        return array_values(array_filter($this->results, static fn (BulkAddRow $r): bool => ! $r->success));
    }
}
