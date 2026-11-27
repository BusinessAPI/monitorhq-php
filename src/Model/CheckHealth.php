<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

final class CheckHealth
{
    public function __construct(
        public readonly ?\DateTimeImmutable $lastCheckedAt,
        public readonly ?int $consecutiveFailures,
        public readonly ?\DateTimeImmutable $acnLastCheckedAt,
        public readonly ?\DateTimeImmutable $abnLastCheckedAt,
        public readonly ?int $acnConsecutiveFailures,
        public readonly ?int $abnConsecutiveFailures,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::date($data, 'last_checked_at'),
            Arr::int($data, 'consecutive_failures'),
            Arr::date($data, 'acn_last_checked_at'),
            Arr::date($data, 'abn_last_checked_at'),
            Arr::int($data, 'acn_consecutive_failures'),
            Arr::int($data, 'abn_consecutive_failures'),
        );
    }
}
