<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use MonitorHQ\Client;
use MonitorHQ\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $client = new Client('YOUR_API_TOKEN');
        $this->assertSame('https://monitorhq.com.au/api/v1', $client->config->baseUrl);
        $this->assertSame(30.0, $client->config->timeout);
        $this->assertSame(2, $client->config->maxRetries);
    }

    public function testOptionsAreApplied(): void
    {
        $client = new Client('YOUR_API_TOKEN', ['base_url' => 'http://localhost:8080/api/v1/', 'timeout' => 5, 'max_retries' => 0]);
        $this->assertSame('http://localhost:8080/api/v1', $client->config->baseUrl);
        $this->assertSame(5.0, $client->config->timeout);
        $this->assertSame(0, $client->config->maxRetries);
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['mhq xxx'];
        yield 'newline' => ["YOUR_API_TOKEN\nX-Evil: 1"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testRejectsUnsafeTokens(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config($key);
    }

    public function testRejectsBadSettings(): void
    {
        foreach ([['base_url' => 'ftp://x'], ['max_retries' => -1], ['timeout' => 0]] as $options) {
            try {
                new Client('YOUR_API_TOKEN', $options);
                $this->fail('expected exception');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
