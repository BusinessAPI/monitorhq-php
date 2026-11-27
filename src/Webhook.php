<?php

declare(strict_types=1);

namespace MonitorHQ;

use MonitorHQ\Exception\WebhookException;

/** Verifies the X-Monitorhq-Signature header of a webhook delivery. */
final class Webhook
{
    public const HEADER = 'X-Monitorhq-Signature';
    public const DEFAULT_TOLERANCE = 300;

    /** @param int|null $now */
    public static function verify(string $rawBody, string $signatureHeader, #[\SensitiveParameter] string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        return self::failure($rawBody, $signatureHeader, $secret, $tolerance, $now) === null;
    }

    public static function constructEvent(string $rawBody, string $signatureHeader, #[\SensitiveParameter] string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): WebhookEvent
    {
        $reason = self::failure($rawBody, $signatureHeader, $secret, $tolerance, $now);
        if ($reason !== null) {
            throw new WebhookException(match ($reason) {
                WebhookException::MALFORMED_HEADER => 'The webhook signature header is malformed.',
                WebhookException::TIMESTAMP_OUT_OF_TOLERANCE => 'The webhook timestamp is outside the allowed tolerance.',
                default => 'No signature in the header matches the payload.',
            }, $reason);
        }

        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookException('The webhook body is not valid JSON.', WebhookException::INVALID_PAYLOAD, $e);
        }
        if (! is_array($data) || ! is_string($data['event'] ?? null)) {
            throw new WebhookException('The webhook body has no event type.', WebhookException::INVALID_PAYLOAD);
        }

        return WebhookEvent::fromArray($data);
    }

    public static function sign(string $rawBody, #[\SensitiveParameter] string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /** @return string|null */
    private static function failure(string $rawBody, string $header, #[\SensitiveParameter] string $secret, int $tolerance, ?int $now): ?string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('The webhook secret must not be empty.');
        }
        if ($tolerance < 0) {
            throw new \InvalidArgumentException('tolerance cannot be negative.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part, ' '), 2);
            if (count($pair) !== 2) {
                return WebhookException::MALFORMED_HEADER;
            }
            [$key, $value] = [trim($pair[0], ' '), trim($pair[1], ' ')];
            // Unknown schemes such as v2 are ignored.
            if ($key === 't') {
                if ($timestamp !== null || preg_match('/^(0|[1-9]\d{0,14})\z/', $value) !== 1) {
                    return WebhookException::MALFORMED_HEADER;
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            return WebhookException::MALFORMED_HEADER;
        }

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return WebhookException::TIMESTAMP_OUT_OF_TOLERANCE;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        $matched = false;
        foreach ($signatures as $candidate) {
            // No early exit: compare every candidate.
            $matched = hash_equals($expected, $candidate) || $matched;
        }

        return $matched ? null : WebhookException::NO_MATCHING_SIGNATURE;
    }
}
