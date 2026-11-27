<?php

declare(strict_types=1);

namespace MonitorHQ;

use MonitorHQ\Http\Transport;
use MonitorHQ\Service\Account;
use MonitorHQ\Service\Identifiers;
use MonitorHQ\Service\Monitorings;
use MonitorHQ\Service\Subscription;
use Psr\Http\Client\ClientInterface;

/** The MonitorHQ API client. */
final class Client
{
    public readonly Config $config;
    public readonly Account $account;
    public readonly Subscription $subscription;
    public readonly Identifiers $identifiers;
    public readonly Monitorings $monitorings;

    /** @param array{base_url?: string, http_client?: ClientInterface, timeout?: float|int, max_retries?: int, sleeper?: \Closure(float): void} $options */
    public function __construct(#[\SensitiveParameter] string $apiKey, array $options = [])
    {
        $this->config = new Config(
            apiKey: $apiKey,
            baseUrl: $options['base_url'] ?? null,
            timeout: (float) ($options['timeout'] ?? 30.0),
            maxRetries: $options['max_retries'] ?? 2,
            sleeper: $options['sleeper'] ?? null,
        );

        $transport = new Transport($this->config, $options['http_client'] ?? null);

        $this->account = new Account($transport);
        $this->subscription = new Subscription($transport);
        $this->identifiers = new Identifiers($transport);
        $this->monitorings = new Monitorings($transport);
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Client holds an API token and cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Client cannot be unserialized.');
    }
}
