<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use MonitorHQ\Client;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected FakeHttpClient $http;

    /** @var list<float> */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->sleeps = [];
    }

    protected function client(int $maxRetries = 2): Client
    {
        return new Client('YOUR_API_TOKEN', [
            'http_client' => $this->http,
            'max_retries' => $maxRetries,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }
}
