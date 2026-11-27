<?php

declare(strict_types=1);

namespace MonitorHQ;

use MonitorHQ\Internal\Arr;
use MonitorHQ\Model\EntityState;

final class WebhookEvent
{
    public const CHANGE_DETECTED = 'change_detected';
    public const TEST = 'test';

    /**
     * @param list<ChangeEvent> $events
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $uuid,
        public readonly ?string $entityName,
        public readonly ?string $acn,
        public readonly ?string $abn,
        public readonly ?EntityState $entityState,
        public readonly array $events,
        public readonly ?\DateTimeImmutable $detectedAt,
        public readonly ?int $deliveryId,
        public readonly ?string $message,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $events = [];
        foreach (Arr::rows($data, 'events') ?? [] as $row) {
            $events[] = ChangeEvent::fromArray($row);
        }
        $state = Arr::map($data, 'entity_state');

        return new self(
            Arr::string($data, 'event') ?? '',
            Arr::string($data, 'uuid'),
            Arr::string($data, 'entity_name'),
            Arr::string($data, 'acn'),
            Arr::string($data, 'abn'),
            $state === null ? null : EntityState::fromArray($state),
            $events,
            Arr::date($data, 'detected_at'),
            Arr::int($data, 'delivery_id'),
            Arr::string($data, 'message'),
            $data,
        );
    }

    public function isTest(): bool
    {
        return $this->type === self::TEST;
    }

    public function isChangeDetected(): bool
    {
        return $this->type === self::CHANGE_DETECTED;
    }

    /** @return list<int> */
    public function historyIds(): array
    {
        $ids = [];
        foreach ($this->events as $event) {
            if ($event->historyId !== null) {
                $ids[] = $event->historyId;
            }
        }

        return $ids;
    }
}
