# FloopFloop PHP SDK

[![CI](https://github.com/FloopFloopAI/floop-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/FloopFloopAI/floop-php-sdk/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/floopfloop/sdk?color=blue)](https://packagist.org/packages/floopfloop/sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/floopfloop/sdk)](https://packagist.org/packages/floopfloop/sdk)
[![License](https://img.shields.io/packagist/l/floopfloop/sdk)](LICENSE)

Official PHP SDK for the [FloopFloop](https://www.floopfloop.com) API — build, refine, and manage FloopFloop projects from any PHP codebase.

Same surface as the [Node](https://github.com/FloopFloopAI/floop-node-sdk), [Python](https://github.com/FloopFloopAI/floop-python-sdk), [Go](https://github.com/FloopFloopAI/floop-go-sdk), [Rust](https://github.com/FloopFloopAI/floop-rust-sdk), and [Ruby](https://github.com/FloopFloopAI/floop-ruby-sdk) SDKs. Calls the same `/api/v1/*` routes as the `floop` CLI.

## Install

```bash
composer require floopfloop/sdk
```

Requires PHP ≥ 8.1 and the `json` extension (bundled by default). No `curl` dependency — the transport uses PHP's stdlib stream layer.

## Quickstart

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FloopFloop\Client;

$client = new Client(apiKey: getenv('FLOOP_API_KEY'));

$created = $client->projects()->create([
    'prompt' => 'A landing page for a cat cafe',
    'subdomain' => 'cat-cafe',
    'botType' => 'site',
]);

$live = $client->projects()->waitForLive($created['project']['id']);
echo "Live at: {$live['url']}\n";
```

Grab an API key at [www.floopfloop.com/account/api-keys](https://www.floopfloop.com/account/api-keys).

## Resources

| Resource        | Methods |
|---|---|
| `projects()`    | `create`, `list`, `get`, `status`, `cancel`, `reactivate`, `refine`, `conversations`, `stream`, `waitForLive` |
| `subdomains()`  | `check`, `suggest` |
| `secrets()`     | `list`, `set`, `remove` |
| `library()`     | `list`, `clone` |
| `usage()`       | `summary` |
| `apiKeys()`     | `list`, `create`, `remove` (accepts id OR name) |
| `uploads()`     | `create` (presign + direct S3 PUT) |
| `user()`        | `me` |

Every method returns an associative array shaped like the API response. Non-2xx responses throw `FloopFloop\Error`.

## Streaming status

```php
$client->projects()->stream('my-subdomain', function (array $event): void {
    echo "[{$event['status']}] step={$event['step']} progress={$event['progress']}\n";
});
```

`stream($ref, $handler, $interval, $maxWait)` polls `GET /api/v1/projects/:id/status` every `$interval` seconds (default 2.0), de-duplicates identical consecutive snapshots, and returns the terminal event (`live`, `failed`, `cancelled`, `archived`). Throws `FloopFloop\Error` with a `BUILD_FAILED` / `BUILD_CANCELLED` / `TIMEOUT` code on non-success terminals.

`waitForLive($ref, $interval, $maxWait)` is a thin wrapper that returns the `live` event or throws.

## Error handling

```php
use FloopFloop\Client;
use FloopFloop\Error;

try {
    $client->projects()->status('p_nonexistent');
} catch (Error $e) {
    if ($e->code === 'RATE_LIMITED') {
        sleep((int) ($e->retryAfter ?? 5));
        // retry ...
    }
    throw $e;
}
```

`FloopFloop\Error` exposes:

- `$code` (string) — application error code. Known values: `UNAUTHORIZED`, `FORBIDDEN`, `VALIDATION_ERROR`, `RATE_LIMITED`, `NOT_FOUND`, `CONFLICT`, `SERVICE_UNAVAILABLE`, `SERVER_ERROR`, `NETWORK_ERROR`, `TIMEOUT`, `BUILD_FAILED`, `BUILD_CANCELLED`, `UNKNOWN`. Unknown server codes pass through verbatim.
- `$status` (int) — HTTP status. `0` for network / timeout failures.
- `$requestId` (?string) — the `x-request-id` response header, when present.
- `$retryAfter` (?float) — seconds parsed from the `Retry-After` response header (delta-seconds OR HTTP-date).
- `getMessage()` — the human-readable message from the server (or a local description for network errors).

## Configuration

```php
$client = new Client(
    apiKey: 'flp_...',
    baseUrl: 'https://staging.floopfloop.com',   // default: https://www.floopfloop.com
    timeout: 60.0,                                // default: 30.0 seconds
    userAgentSuffix: 'myapp/1.2.3',               // appended to floopfloop-php-sdk/<version>
);
```

To inject a test double, pass an `HttpClient` implementation:

```php
use FloopFloop\HttpClient;
use FloopFloop\Client;

final class CurlHttpClient implements HttpClient { /* ... */ }

$client = new Client(apiKey: '...', httpClient: new CurlHttpClient());
```

## Development

```bash
composer install
vendor/bin/phpunit
```

Tests run offline against an in-memory `FakeHttpClient` — no network, no backend dependency.

## License

MIT — see [LICENSE](LICENSE).
