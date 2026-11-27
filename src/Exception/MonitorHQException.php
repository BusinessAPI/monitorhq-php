<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** Base class for API errors. */
class MonitorHQException extends \RuntimeException
{
    /** @param array<mixed>|null $body */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        public readonly ?array $body = null,
        public readonly string $rawBody = '',
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
