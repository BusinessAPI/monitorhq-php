<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

final class BulkAddRow
{
    public function __construct(
        public readonly int $index,
        public readonly bool $success,
        public readonly ?string $uuid,
        public readonly ?string $error,
    ) {
    }
}
