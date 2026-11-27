<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;
use MonitorHQ\RiskLevel;

final class MonitoringSnapshot
{
    /**
     * @param array<string, mixed> $entityDetails
     * @param list<array<string, mixed>>|null $tradeMarks
     * @param list<array<string, mixed>>|null $registrations
     * @param list<array<string, mixed>>|null $insolvencyNotices
     * @param array<string, mixed>|null $ppsr
     * @param list<HistoryItem> $recentHistory
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?RiskLevel $riskLevel,
        public readonly ?\DateTimeImmutable $addedAt,
        public readonly CheckHealth $health,
        public readonly ?EntityState $entityState,
        public readonly array $entityDetails,
        public readonly ?array $tradeMarks,
        public readonly ?\DateTimeImmutable $tradeMarksSyncedAt,
        public readonly ?array $registrations,
        public readonly ?\DateTimeImmutable $registrationsUpdatedAt,
        public readonly ?array $insolvencyNotices,
        public readonly ?array $ppsr,
        public readonly array $recentHistory,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $risk = Arr::string($data, 'risk_level');
        $history = [];
        foreach (Arr::rows($data, 'recent_history') ?? [] as $row) {
            $history[] = HistoryItem::fromArray($row);
        }

        return new self(
            Arr::string($data, 'uuid') ?? '',
            $risk === null ? null : RiskLevel::tryFrom($risk),
            Arr::date($data, 'added_at'),
            CheckHealth::fromArray($data),
            EntityState::fromNullable(Arr::map($data, 'entity_state')),
            Arr::map($data, 'entity_details') ?? [],
            Arr::rows($data, 'trade_marks'),
            Arr::date($data, 'trade_marks_synced_at'),
            Arr::rows($data, 'registrations'),
            Arr::date($data, 'registrations_updated_at'),
            Arr::rows($data, 'insolvency_notices'),
            Arr::map($data, 'ppsr'),
            $history,
            $data,
        );
    }

    public function isLive(): bool
    {
        return $this->entityState !== null;
    }
}
