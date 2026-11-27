<?php

declare(strict_types=1);

namespace MonitorHQ\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A scripted PSR-18 client: returns queued responses (or throws queued exceptions) and records requests. */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    /** @param array<string, string|list<string>> $headers */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function push(ResponseInterface|\Throwable ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpClient: no response queued for ' . $request->getMethod() . ' ' . $request->getUri());
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function last(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /** @return array<mixed> */
    public function lastJson(): array
    {
        $decoded = json_decode((string) $this->last()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

final class FakeNetworkException extends \RuntimeException implements ClientExceptionInterface
{
}
