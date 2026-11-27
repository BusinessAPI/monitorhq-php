<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** HTTP 422. */
class ValidationException extends MonitorHQException
{
    /**
     * @param array<mixed>|null $body
     * @param array<string, list<string>> $fieldErrors
     * @param list<string> $messages
     */
    public function __construct(
        string $message,
        int $statusCode = 422,
        ?string $errorCode = null,
        ?array $body = null,
        string $rawBody = '',
        ?string $requestId = null,
        public readonly array $fieldErrors = [],
        public readonly array $messages = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $body, $rawBody, $requestId, $previous);
    }
}
