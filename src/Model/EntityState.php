<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

final class EntityState
{
    /**
     * @param list<mixed> $businessNames
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?string $entityType,
        public readonly ?string $acnStatus,
        public readonly ?string $abnStatus,
        public readonly ?bool $gstRegistered,
        public readonly ?string $gstDate,
        public readonly array $businessNames,
        public readonly bool $isTerminal,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $names = $data['business_names'] ?? [];

        return new self(
            Arr::string($data, 'entity_type'),
            Arr::string($data, 'acn_status'),
            Arr::string($data, 'abn_status'),
            Arr::bool($data, 'gst_registered'),
            Arr::string($data, 'gst_date'),
            is_array($names) ? array_values($names) : [],
            Arr::bool($data, 'is_terminal') ?? false,
            self::stringKeys($data),
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @param array<mixed>|null $data */
    public static function fromNullable(?array $data): ?self
    {
        return $data === null ? null : self::fromArray($data);
    }
}
