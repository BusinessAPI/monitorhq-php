<?php

declare(strict_types=1);

namespace MonitorHQ\Internal;

// The value lives in a WeakMap so dumps and serialize() of the owning object cannot reach it.
final class Secret
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A secret cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A secret cannot be unserialized.');
    }
}
