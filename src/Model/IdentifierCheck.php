<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

final class IdentifierCheck
{
    /**
     * @param list<string> $errors
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $type,
        public readonly ?string $formatted,
        public readonly ?string $number,
        public readonly ?string $message,
        public readonly array $errors,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::bool($data, 'valid') ?? false,
            Arr::string($data, 'type'),
            Arr::string($data, 'formatted'),
            Arr::string($data, 'number'),
            Arr::string($data, 'message'),
            Arr::strings($data, 'errors'),
            $data,
        );
    }
}
