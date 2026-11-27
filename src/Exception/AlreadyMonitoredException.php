<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** HTTP 422. */
final class AlreadyMonitoredException extends ValidationException
{
    /** @param array<mixed>|null $body */
    public function __construct(
        string $message,
        ?array $body = null,
        string $rawBody = '',
        ?string $requestId = null,
        public readonly ?string $uuid = null,
    ) {
        parent::__construct($message, 422, 'already_monitored', $body, $rawBody, $requestId);
    }
}
