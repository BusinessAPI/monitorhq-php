<?php

declare(strict_types=1);

namespace MonitorHQ\Http;

use MonitorHQ\Config;
use MonitorHQ\Exception\AlreadyMonitoredException;
use MonitorHQ\Exception\AuthenticationException;
use MonitorHQ\Exception\CapacityExceededException;
use MonitorHQ\Exception\ConflictException;
use MonitorHQ\Exception\ConnectionException;
use MonitorHQ\Exception\MonitorHQException;
use MonitorHQ\Exception\NotFoundException;
use MonitorHQ\Exception\PermissionException;
use MonitorHQ\Exception\RateLimitException;
use MonitorHQ\Exception\ServerException;
use MonitorHQ\Exception\ValidationException;
use MonitorHQ\Model\Report;
use MonitorHQ\Version;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Transport
{
    public const MAX_RETRY_DELAY = 60.0;

    public const MAX_REPORT_BYTES = 52428800;

    private ClientInterface $client;
    private RequestFactoryInterface $requests;
    private StreamFactoryInterface $streams;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $factory = new HttpFactory();
        $this->client = $client ?? new GuzzleClient(['timeout' => $config->timeout, 'http_errors' => false, 'allow_redirects' => false]);
        $this->requests = $requests ?? $factory;
        $this->streams = $streams ?? $factory;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->send($method, $path, $query, $body, 'application/json');
        $raw = (string) $response->getBody();

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MonitorHQException(
                'The API returned a response that was not valid JSON.',
                $response->getStatusCode(),
                null,
                null,
                $raw,
                $this->requestId($response),
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new MonitorHQException(
                'The API returned an unexpected response.',
                $response->getStatusCode(),
                null,
                null,
                $raw,
                $this->requestId($response),
            );
        }

        return $decoded;
    }

    /** @param array<string, mixed> $query */
    public function download(string $path, array $query = []): Report
    {
        $response = $this->send('GET', $path, $query, null, 'application/pdf, application/json');
        $type = $response->getHeaderLine('Content-Type');
        $size = $response->getBody()->getSize();
        $fail = fn (string $why): MonitorHQException => new MonitorHQException(
            'The report response is not a usable PDF (' . $why . ').',
            $response->getStatusCode(),
            null,
            null,
            '',
            $this->requestId($response),
        );

        if ($size !== null && $size > self::MAX_REPORT_BYTES) {
            throw $fail('too large');
        }
        if ($type !== '' && stripos($type, 'pdf') === false && stripos($type, 'octet-stream') === false) {
            throw $fail('unexpected content type');
        }
        $contents = (string) $response->getBody();
        if ($contents === '') {
            throw $fail('empty body');
        }
        if (strlen($contents) > self::MAX_REPORT_BYTES) {
            throw $fail('too large');
        }
        if (! str_starts_with($contents, '%PDF-')) {
            throw $fail('missing PDF header');
        }

        return new Report($contents, $this->filename($response), 'application/pdf');
    }

    /** @param array<string, mixed> $query */
    public static function buildQuery(array $query): string
    {
        $clean = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $clean[$key] = $value;
        }

        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, array $query, ?array $body, string $accept): ResponseInterface
    {
        $url = $this->config->baseUrl . '/' . ltrim($path, '/');
        $qs = self::buildQuery($query);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }

        $json = null;
        if ($body !== null) {
            try {
                $json = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('The request body could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
            }
        }

        $method = strtoupper($method);
        // POST and PUT are never replayed after a 5xx or dropped connection: the server may have applied them.
        $idempotent = $method === 'GET' || $method === 'DELETE' || $method === 'HEAD';
        $attempt = 0;

        while (true) {
            $request = $this->requests->createRequest($method, $url)
                ->withHeader('Authorization', 'Bearer ' . $this->config->bearerToken())
                ->withHeader('Accept', $accept)
                ->withHeader('User-Agent', 'monitorhq-php/' . Version::VERSION . ' php/' . PHP_VERSION);

            if ($json !== null) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streams->createStream($json));
            }

            try {
                $response = $this->client->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($idempotent && $attempt < $this->config->maxRetries) {
                    $this->wait($this->backoff($attempt++));
                    continue;
                }
                throw new ConnectionException('Could not reach the MonitorHQ API: ' . $e->getMessage(), 0, null, null, '', null, $e);
            }

            $status = $response->getStatusCode();

            // 429 was refused before it ran, so it is safe to resend any method.
            if ($status === 429 && $attempt < $this->config->maxRetries) {
                $retryAfter = $this->retryAfter($response);
                $this->wait($retryAfter !== null ? min((float) $retryAfter, self::MAX_RETRY_DELAY) : $this->backoff($attempt));
                $attempt++;
                continue;
            }

            if ($status >= 500 && $idempotent && $attempt < $this->config->maxRetries) {
                $this->wait($this->backoff($attempt++));
                continue;
            }

            if ($status >= 300 && $status < 400) {
                $location = preg_replace('/[\x00-\x1f\x7f]/', '', $response->getHeaderLine('Location')) ?? '';

                throw new MonitorHQException(
                    'The API answered with a redirect (HTTP ' . $status . ($location !== '' ? ' to ' . $location : '') . '). Redirects are not followed, so check base_url.',
                    $status,
                    null,
                    null,
                    '',
                    $this->requestId($response),
                );
            }

            if ($status >= 400) {
                throw $this->toException($response);
            }

            return $response;
        }
    }

    private function toException(ResponseInterface $response): MonitorHQException
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : null;
        $requestId = $this->requestId($response);

        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = is_array($body) && is_string($body['message'] ?? null) && $body['message'] !== ''
            ? $body['message']
            : 'The MonitorHQ API returned HTTP ' . $status . '.';

        if ($status === 422) {
            if ($code === 'already_monitored') {
                $uuid = is_string($body['uuid'] ?? null) ? $body['uuid'] : null;

                return new AlreadyMonitoredException($message, $body, $raw, $requestId, $uuid);
            }
            if ($code === 'capacity_exceeded') {
                return new CapacityExceededException($message, $body, $raw, $requestId);
            }

            [$fieldErrors, $messages] = $this->readErrors($body);

            return new ValidationException($message, 422, $code, $body, $raw, $requestId, $fieldErrors, $messages);
        }

        return match (true) {
            $status === 401 => new AuthenticationException($message, 401, $code, $body, $raw, $requestId),
            $status === 403 => new PermissionException($message, 403, $code, $body, $raw, $requestId),
            $status === 404 => new NotFoundException($message, 404, $code, $body, $raw, $requestId),
            $status === 409 => new ConflictException($message, 409, $code, $body, $raw, $requestId),
            $status === 429 => new RateLimitException($message, $code, $body, $raw, $requestId, $this->retryAfter($response)),
            $status >= 500 => new ServerException($message, $status, $code, $body, $raw, $requestId),
            default => new MonitorHQException($message, $status, $code, $body, $raw, $requestId),
        };
    }

    /**
     * @param array<mixed>|null $body
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function readErrors(?array $body): array
    {
        $fields = [];
        $messages = [];
        $errors = is_array($body) ? ($body['errors'] ?? null) : null;

        if (is_array($errors)) {
            foreach ($errors as $field => $list) {
                $items = [];
                foreach ((array) $list as $m) {
                    if (is_string($m)) {
                        $items[] = $m;
                    }
                }
                if (is_int($field)) {
                    $messages = array_merge($messages, $items);
                } else {
                    $fields[$field] = $items;
                }
            }
        }

        return [$fields, $messages];
    }

    private function filename(ResponseInterface $response): ?string
    {
        $disposition = $response->getHeaderLine('Content-Disposition');
        if ($disposition === '') {
            return null;
        }
        if (preg_match('/filename\*=(?:UTF-8\'\')?([^;]+)/i', $disposition, $m) === 1) {
            return $this->safeFilename(rawurldecode(trim($m[1], " \"")));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return $this->safeFilename($m[1]);
        }

        return null;
    }

    private function safeFilename(string $name): ?string
    {
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $name = basename(str_replace('\\', '/', $name));

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }

    private function requestId(ResponseInterface $response): ?string
    {
        foreach (['X-Request-Id', 'X-Amzn-Requestid', 'Request-Id'] as $header) {
            $value = $response->getHeaderLine($header);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }

    private function backoff(int $attempt): float
    {
        $base = min(0.5 * (2 ** $attempt), 8.0);

        return $base / 2 + (mt_rand() / mt_getrandmax()) * ($base / 2);
    }

    private function wait(float $seconds): void
    {
        if ($this->config->sleeper !== null) {
            ($this->config->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
