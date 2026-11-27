<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

final class Subscription
{
    /** @param array<mixed> $raw */
    public function __construct(
        public readonly bool $hasSubscription,
        public readonly ?string $message,
        public readonly ?string $plan,
        public readonly ?string $status,
        public readonly ?\DateTimeImmutable $subscribedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?int $maxEntities,
        public readonly ?int $maxHighRiskEntities,
        public readonly ?int $usageTotal,
        public readonly ?int $usageHigh,
        public readonly ?int $usageStandard,
        public readonly ?int $usageLow,
        public readonly ?int $remainingSlots,
        public readonly ?int $remainingHighRiskSlots,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $sub = Arr::map($data, 'subscription') ?? [];
        $usage = Arr::map($data, 'usage') ?? [];
        $remaining = Arr::map($data, 'remaining') ?? [];

        return new self(
            Arr::bool($data, 'has_subscription') ?? false,
            Arr::string($data, 'message'),
            Arr::string($sub, 'plan'),
            Arr::string($sub, 'status'),
            Arr::date($sub, 'subscribed_at'),
            Arr::date($sub, 'expires_at'),
            Arr::int($sub, 'max_entities'),
            Arr::int($sub, 'max_high_risk_entities'),
            Arr::int($usage, 'total'),
            Arr::int($usage, 'high'),
            Arr::int($usage, 'standard'),
            Arr::int($usage, 'low'),
            Arr::int($remaining, 'slots'),
            Arr::int($remaining, 'high_risk_slots'),
            $data,
        );
    }
}
