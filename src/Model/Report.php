<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

final class Report implements \Stringable
{
    public function __construct(
        public readonly string $contents,
        public readonly ?string $filename,
        public readonly string $contentType = 'application/pdf',
    ) {
    }

    public function save(string $path): int
    {
        $written = @file_put_contents($path, $this->contents);
        if ($written === false) {
            throw new \RuntimeException('Could not write the report to ' . $path);
        }

        return $written;
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}
