<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;
use MonitorHQ\RiskLevel;

final class Monitoring
{
    /** @param array<mixed> $raw */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $entityName,
        public readonly ?string $entityType,
        public readonly ?string $acn,
        public readonly ?string $abn,
        public readonly ?RiskLevel $riskLevel,
        public readonly ?\DateTimeImmutable $addedAt,
        public readonly CheckHealth $health,
        public readonly ?EntityState $entityState,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $risk = Arr::string($data, 'risk_level');
        $state = Arr::map($data, 'entity_state');

        return new self(
            Arr::string($data, 'uuid') ?? '',
            Arr::string($data, 'entity_name'),
            Arr::string($data, 'entity_type'),
            Arr::string($data, 'acn'),
            Arr::string($data, 'abn'),
            $risk === null ? null : RiskLevel::tryFrom($risk),
            Arr::date($data, 'added_at'),
            CheckHealth::fromArray($data),
            EntityState::fromNullable($state),
            $data,
        );
    }
}
