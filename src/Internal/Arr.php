<?php

declare(strict_types=1);

namespace MonitorHQ\Internal;

final class Arr
{
    /** @param array<mixed> $a */
    public static function string(array $a, string $key): ?string
    {
        $v = $a[$key] ?? null;

        return is_string($v) ? $v : (is_int($v) ? (string) $v : null);
    }

    /** @param array<mixed> $a */
    public static function int(array $a, string $key): ?int
    {
        $v = $a[$key] ?? null;

        return is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : null);
    }

    /** @param array<mixed> $a */
    public static function bool(array $a, string $key): ?bool
    {
        $v = $a[$key] ?? null;

        return is_bool($v) ? $v : null;
    }

    /**
     * @param array<mixed> $a
     * @return array<string, mixed>|null
     */
    public static function map(array $a, string $key): ?array
    {
        $v = $a[$key] ?? null;
        if (! is_array($v)) {
            return null;
        }

        $out = [];
        foreach ($v as $k => $item) {
            $out[(string) $k] = $item;
        }

        return $out;
    }

    /**
     * @param array<mixed> $a
     * @return list<array<string, mixed>>|null
     */
    public static function rows(array $a, string $key): ?array
    {
        $v = $a[$key] ?? null;
        if (! is_array($v)) {
            return null;
        }

        $out = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $row = [];
                foreach ($item as $k => $val) {
                    $row[(string) $k] = $val;
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>
     */
    public static function strings(array $a, string $key): array
    {
        $v = $a[$key] ?? null;
        if (! is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param array<mixed> $a */
    public static function date(array $a, string $key): ?\DateTimeImmutable
    {
        $v = $a[$key] ?? null;
        if (! is_string($v) || $v === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }
}
