<?php

declare(strict_types=1);

namespace MonitorHQ;

use MonitorHQ\Internal\Secret;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://monitorhq.com.au/api/v1';

    public const MAX_RETRIES_LIMIT = 5;

    public readonly string $baseUrl;

    private readonly Secret $secret;

    /** @param \Closure(float): void|null $sleeper */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?string $baseUrl = null,
        public readonly float $timeout = 30.0,
        public readonly int $maxRetries = 2,
        public readonly ?\Closure $sleeper = null,
    ) {
        if ($apiKey === '' || preg_match('/[\s\x00-\x1f\x7f]/', $apiKey) === 1) {
            throw new \InvalidArgumentException('The API token must be a non-empty string without whitespace.');
        }
        $this->secret = new Secret($apiKey);

        if ($maxRetries < 0 || $maxRetries > self::MAX_RETRIES_LIMIT) {
            throw new \InvalidArgumentException('max_retries must be between 0 and ' . self::MAX_RETRIES_LIMIT . '.');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than zero.');
        }

        $url = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if (! is_array($parts) || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('base_url must be an https URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('base_url must not contain credentials, a query string or a fragment.');
        }
        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \InvalidArgumentException('base_url must use https (plain http is only allowed for localhost).');
        }
        $this->baseUrl = $url;
    }

    public function bearerToken(): string
    {
        return $this->secret->reveal();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]', 'baseUrl' => $this->baseUrl, 'timeout' => $this->timeout, 'maxRetries' => $this->maxRetries];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Config holds an API token and cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Config cannot be unserialized.');
    }
}
