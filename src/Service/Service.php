<?php

declare(strict_types=1);

namespace MonitorHQ\Service;

use MonitorHQ\Http\Transport;

abstract class Service
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    protected function seg(string $value): string
    {
        if ($value === '' || $value === '.' || $value === '..' || preg_match('#[/?\#\\\\\x00-\x1f\x7f]#', $value) === 1) {
            throw new \InvalidArgumentException('Invalid path segment: ' . $value);
        }

        return rawurlencode($value);
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>
     */
    protected function unwrap(array $body): array
    {
        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    protected function filters(array $filters, array $allowed): array
    {
        $unknown = array_diff(array_keys($filters), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown filter(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed) . '.'
            );
        }

        return $filters;
    }
}
