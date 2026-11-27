<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

/**
 * One page of a paginated list.
 *
 * @template T
 */
final class Page
{
    /**
     * @param list<T> $items
     * @param array<mixed> $links
     * @param array<mixed> $meta
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly ?int $perPage,
        public readonly ?string $nextUrl,
        public readonly ?string $prevUrl,
        public readonly array $links,
        public readonly array $meta,
        public readonly array $raw,
    ) {
    }

    /**
     * @template U
     * @param array<mixed> $body
     * @param callable(array<mixed>): U $map
     * @return Page<U>
     */
    public static function fromArray(array $body, callable $map): self
    {
        $items = [];
        foreach (Arr::rows($body, 'data') ?? [] as $row) {
            $items[] = $map($row);
        }

        $links = Arr::map($body, 'links') ?? [];
        $meta = Arr::map($body, 'meta') ?? [];

        return new self(
            $items,
            Arr::int($meta, 'current_page') ?? 1,
            Arr::int($meta, 'per_page'),
            Arr::string($links, 'next'),
            Arr::string($links, 'prev'),
            $links,
            $meta,
            $body,
        );
    }

    public function hasNextPage(): bool
    {
        return $this->nextUrl !== null;
    }
}
