<?php

declare(strict_types=1);

namespace MonitorHQ;

use MonitorHQ\Internal\Arr;

final class ChangeEvent
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly ?int $historyId,
        public readonly EventType $type,
        public readonly string $typeRaw,
        public readonly ?string $source,
        public readonly bool $initial,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?\DateTimeImmutable $effectiveDate,
        public readonly array $metadata,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = Arr::string($data, 'event_type') ?? '';

        return new self(
            Arr::int($data, 'history_id'),
            EventType::fromApi($type),
            $type,
            Arr::string($data, 'source'),
            Arr::bool($data, 'initial') ?? false,
            Arr::string($data, 'title'),
            Arr::string($data, 'description'),
            Arr::date($data, 'effective_date'),
            Arr::map($data, 'metadata') ?? [],
            $data,
        );
    }
}
