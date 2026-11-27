<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** HTTP 429. */
final class RateLimitException extends MonitorHQException
{
    /**
     * @param array<mixed>|null $body
     * @param int|null $retryAfterSeconds
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?array $body = null,
        string $rawBody = '',
        ?string $requestId = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 429, $errorCode, $body, $rawBody, $requestId);
    }
}
