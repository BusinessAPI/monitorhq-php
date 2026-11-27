<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** HTTP 422. */
final class CapacityExceededException extends ValidationException
{
    /** @param array<mixed>|null $body */
    public function __construct(string $message, ?array $body = null, string $rawBody = '', ?string $requestId = null)
    {
        parent::__construct($message, 422, 'capacity_exceeded', $body, $rawBody, $requestId);
    }
}
