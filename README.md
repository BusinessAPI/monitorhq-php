# MonitorHQ SDK for PHP

Official PHP SDK for the [MonitorHQ](https://monitorhq.com.au) API: Australian company, trust, sole trader and ABN monitoring. Track ASIC and ABR changes, PPSR registrations, trade marks and licence registers, read entity snapshots and history, download PDF reports and verify webhooks.

## Install

```bash
composer require businessapi/monitorhq-php
```

Requires PHP 8.1+. Uses Guzzle, or any PSR-18 client.

## Quick start

```php
use MonitorHQ\Client;
use MonitorHQ\RiskLevel;

$client = new Client(getenv('MONITORHQ_API_TOKEN'));

$monitoring = $client->monitorings->add(['acn' => '123456789', 'risk_level' => RiskLevel::Standard]);
$snapshot = $client->monitorings->get($monitoring->uuid);
echo $snapshot->entityState?->acnStatus;
```

## What you can monitor

Companies by ACN, and any other entity with an ABN: trusts, sole traders, partnerships and more.

- ABR: ABN status, GST registration and business names (every entity)
- ASIC company details: status, addresses and lodged documents (companies)
- PPSR security interest registrations (ACN, ARBN and ARSN entities, not ABN-only)
- ASIC insolvency notices (companies)
- IP Australia trade marks owned by the entity
- ASIC AFS and credit licences and representatives, and the ACNC charity register

Entities are matched by ABN or ACN, never by name.

## Authentication

Create an API token in MonitorHQ and pass it to `Client`. Each call needs a matching token ability: `read`, `create`, `update` or `delete`. Read the token from the environment, never from source control.

```php
new Client($token, [
    'base_url' => 'https://monitorhq.com.au/api/v1',
    'timeout' => 30,        // default Guzzle client only
    'max_retries' => 2,     // 0 to 5
    'http_client' => $psr18,
]);
```

`base_url` must be https (http only for localhost). Redirects are not followed. A custom `http_client` must return 4xx and 5xx responses instead of throwing.

## Methods

| Method | Does |
| --- | --- |
| `account->get()` | The token's user and teams |
| `subscription->get()` | Plan, limits and usage |
| `identifiers->check($id)` | Validate and format an ACN or ABN |
| `monitorings->list($filters)` | One page of monitorings |
| `monitorings->listAll($filters)` | Generator over all pages |
| `monitorings->add($data)` | Start monitoring by ACN or ABN |
| `monitorings->bulkAdd($rows)` | Add up to 100 entities, rows succeed or fail independently |
| `monitorings->get($uuid)` | Latest snapshot of everything held |
| `monitorings->history($uuid, $filters)` | One page of history |
| `monitorings->historyAll($uuid, $filters)` | Generator over all history pages |
| `monitorings->update($uuid, $risk)` | Change the risk level |
| `monitorings->stop($uuid)` | Stop monitoring (team owners) |
| `monitorings->report($uuid, includeRecent: true)` | PDF report: `->contents`, `->filename`, `->save($path)` |
| `monitorings->findByAcn()`, `findByAbn()` | Look up your monitoring by identifier |

Every model has the decoded response in `->raw`.

## Pagination

Lists use simple pagination: no total, only `hasNextPage()`. Use `listAll()` and `historyAll()` to iterate. For incremental sync pass `detected_since` to `history`: rows come oldest first.

```php
foreach ($client->monitorings->historyAll($uuid, ['detected_since' => new DateTimeImmutable('-7 days')]) as $item) {
    echo $item->type->value, ' ', $item->title, "\n";
}
```

Unknown event types map to `EventType::OtherChange`.

## Errors

All extend `MonitorHQ\Exception\MonitorHQException` (`statusCode`, `errorCode`, `body`, `requestId`).

- `AuthenticationException` 401
- `PermissionException` 403
- `NotFoundException` 404
- `ConflictException` 409
- `ValidationException` 422 (`fieldErrors`), with `AlreadyMonitoredException` (`uuid`) and `CapacityExceededException`
- `RateLimitException` 429 (`retryAfterSeconds`)
- `ServerException` 5xx
- `ConnectionException` no response

## Webhooks

Verify the `X-Monitorhq-Signature` header against the raw request body, never a re-encoded one.

```php
use MonitorHQ\Exception\WebhookException;
use MonitorHQ\Webhook;

try {
    $event = Webhook::constructEvent($request->getContent(), $request->header('X-Monitorhq-Signature'), $secret);
} catch (WebhookException $e) {
    return response('Invalid', 400);
}
foreach ($event->events as $change) {
    handle($event->uuid, $change->historyId, $change->type);
}
```

`Webhook::verify()` returns a bool. The default tolerance is 300 seconds. Dedupe on `deliveryId` and each `historyId`. A `test` delivery has no `deliveryId`. Exempt the route from CSRF protection. A null `$secret` raises a `TypeError`.

## Retries and rate limits

- The API allows 60 requests a minute per token and 30 for reports.
- GET and DELETE retry on network errors and 5xx, with backoff and jitter.
- Any method retries on 429, waiting for `Retry-After` (up to 60 seconds).
- POST and PUT are never retried after a 5xx or dropped connection.

## Links

- [API reference](https://monitorhq.com.au/docs)
- [Issues](https://github.com/BusinessAPI/monitorhq-php/issues)

## Licence

MIT
