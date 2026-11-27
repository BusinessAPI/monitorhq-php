<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** A webhook failed verification or could not be parsed. */
final class WebhookException extends MonitorHQException
{
    public const MALFORMED_HEADER = 'malformed_header';
    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';
    public const NO_MATCHING_SIGNATURE = 'no_matching_signature';
    public const INVALID_PAYLOAD = 'invalid_payload';

    public function __construct(string $message, public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $reason, null, '', null, $previous);
    }
}
