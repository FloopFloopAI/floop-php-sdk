# Cookbook

Concrete `floopfloop/sdk` (Packagist) patterns you can copy-paste. Every snippet uses only the SDK's public surface — no undocumented endpoints, no private helpers.

For the basics (install, client setup, resource tour) see the [README](../README.md). This file is the **"I know the basics, now how do I actually build X"** layer.

These recipes mirror the [Node](https://github.com/FloopFloopAI/floop-node-sdk/blob/main/docs/recipes.md), [Python](https://github.com/FloopFloopAI/floop-python-sdk/blob/main/docs/recipes.md), [Go](https://github.com/FloopFloopAI/floop-go-sdk/blob/main/docs/recipes.md), [Rust](https://github.com/FloopFloopAI/floop-rust-sdk/blob/main/docs/recipes.md), and [Ruby](https://github.com/FloopFloopAI/floop-ruby-sdk/blob/main/docs/recipes.md) cookbooks, translated to PHP 8.1+ idioms (named arguments at call sites, callable-based stream, `try/catch (FloopFloop\Error)`).

---

## 1. Ship a project from prompt to live URL

The canonical one-call flow: create, wait, done. `waitForLive` throws `FloopFloop\Error` with `code === 'BUILD_FAILED'` / `'BUILD_CANCELLED'` / `'TIMEOUT'` on non-success terminals, so a plain `try/catch` is enough.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FloopFloop\Client;
use FloopFloop\Error;

$client = new Client(apiKey: getenv('FLOOP_API_KEY'));

function ship(Client $client, string $prompt, string $subdomain): string {
    $created = $client->projects()->create(
        prompt: $prompt,
        subdomain: $subdomain,
        botType: 'site',
    );
    $projectId = $created['project']['id'];

    try {
        // Polls status every 2s; bounds the total wait to 10 minutes
        // so a stuck build doesn't hang forever.
        $live = $client->projects()->waitForLive(
            $projectId,
            interval: 2.0,
            maxWait: 600.0,
        );
        return $live['url'];
    } catch (Error $e) {
        if ($e->code === 'BUILD_FAILED') {
            error_log("build failed: {$e->getMessage()}");
        }
        throw $e;
    }
}

$url = ship(
    $client,
    'A single-page portfolio for a landscape photographer',
    'landscape-portfolio',
);
echo "Live at {$url}\n";
```

`maxWait` is in **seconds** (float). Default is 600.0. Use named-argument call syntax — the SDK exposes positional args with sensible defaults but everything reads cleaner with `interval:` / `maxWait:` at the call site.

**When to prefer `stream` over `waitForLive`:** when you want to show progress to a user. `waitForLive` only returns at the end — no visibility into what the build is doing.

---

## 2. Watch a build progress in real time

`projects()->stream($ref, $handler)` calls your `$handler` for every unique status snapshot until the project reaches a terminal state (`live` / `failed` / `cancelled`) or `maxWait` elapses. Events are de-duplicated on `(status, step, progress, queuePosition)` so the handler doesn't fire on every poll.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FloopFloop\Client;
use FloopFloop\Error;

$client = new Client(apiKey: getenv('FLOOP_API_KEY'));

$created = $client->projects()->create(
    prompt: 'A recipe blog with a dark theme',
    subdomain: 'recipe-blog',
    botType: 'site',
);
$projectId = $created['project']['id'];

try {
    $client->projects()->stream($projectId, function (array $event): void {
        $progress = isset($event['progress']) ? sprintf(' %d%%', (int) $event['progress']) : '';
        $step     = !empty($event['step'])     ? " — {$event['step']}" : '';
        echo "[{$event['status']}]{$progress}{$step}\n";
    });
} catch (Error $e) {
    match ($e->code) {
        'BUILD_FAILED'    => exit("build failed: {$e->getMessage()}\n"),
        'BUILD_CANCELLED' => exit("user cancelled build\n"),
        'TIMEOUT'         => exit("build stalled past maxWait\n"),
        default           => throw $e,
    };
}

// Reached "live" cleanly — fetch the hydrated project.
$done = $client->projects()->get($projectId);
echo "Live at {$done['url']}\n";
```

**Early abort.** Throw any exception from inside the handler and the SDK propagates it — so callers can tell their own sentinel apart from the SDK's terminal errors:

```php
class EnoughProgress extends \RuntimeException {}

try {
    $client->projects()->stream('recipe-blog', function (array $event): void {
        if (isset($event['progress']) && $event['progress'] >= 50) {
            throw new EnoughProgress();
        }
        echo "[{$event['status']}] {$event['progress']}%\n";
    });
} catch (EnoughProgress) {
    echo "saw enough progress, moving on\n";
}
```

The SDK doesn't catch caller exceptions, so they bubble naturally — no need for in-band error codes.

---

## 3. Refine a project, even when it's mid-build

`projects()->refine` returns the raw hash from the backend. Three mutually-exclusive shapes:

- `['queued' => true, 'messageId' => ...]` — project is currently deploying; your message is queued and will be processed when the current build finishes.
- `['processing' => true, 'deploymentId' => ..., 'queuePriority' => N]` — your message triggered a new build immediately.
- `['queued' => false]` — saved as a conversation entry without triggering a build.

```php
<?php
$result = $client->projects()->refine(
    'recipe-blog',
    message: 'Add a search bar to the header',
);

match (true) {
    !empty($result['processing']) => (function () use ($client, $result) {
        echo "Build started (deployment {$result['deploymentId']})\n";
        $client->projects()->waitForLive('recipe-blog');
    })(),
    !empty($result['queued']) => (function () use ($client, $result) {
        echo "Queued behind current build (message {$result['messageId']})\n";
        // Poll once — when "live", your queued message has been processed.
        $client->projects()->waitForLive('recipe-blog');
    })(),
    default => print "Saved as a chat message, no build triggered\n",
};
```

Like the Ruby SDK, `refine` returns the raw decoded JSON — keys stay camelCase from the wire (`messageId`, `deploymentId`, `queuePriority`).

If the IIFE-in-`match` reads awkwardly, a plain `if/elseif/else` chain is fine:

```php
if (!empty($result['processing'])) {
    echo "Build started (deployment {$result['deploymentId']})\n";
    $client->projects()->waitForLive('recipe-blog');
} elseif (!empty($result['queued'])) {
    echo "Queued behind current build\n";
    $client->projects()->waitForLive('recipe-blog');
} else {
    echo "Saved as a chat message, no build triggered\n";
}
```

---

## 4. Upload an image and refine with it as context

Uploads are two-step: `uploads()->create` (or the disk-reading shortcut `uploads()->createFromPath`) presigns an S3 URL and PUTs the bytes for you, returning an attachment hash you can drop directly into `refine`'s `attachments` array. **No type conversion needed** — same hash shape on both sides (matches Ruby; unlike Go/Rust where `fileSize` types differ).

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FloopFloop\Client;

$client = new Client(apiKey: getenv('FLOOP_API_KEY'));

// Convenience helper — reads the file for you.
$attachment = $client->uploads()->createFromPath('./mockup.png');

// Or pass bytes directly if you already have the payload:
// $bytes = file_get_contents('./mockup.png');
// $attachment = $client->uploads()->create('mockup.png', $bytes);

$client->projects()->refine(
    'recipe-blog',
    message: 'Make the homepage look like this mockup.',
    attachments: [$attachment],
);
```

**Supported types:** `png`, `jpg/jpeg`, `gif`, `svg`, `webp`, `ico`, `pdf`, `txt`, `csv`, `doc`, `docx`. Max 5 MB per upload. The SDK validates client-side before hitting the network, so bad inputs throw `FloopFloop\Error` with `code === 'VALIDATION_ERROR'` and no round-trip.

Attachments only flow through `refine` today — `create` doesn't accept them via the SDK. If you need to anchor a brand-new project against images, create with a prompt first, then refine with the attachments as a follow-up.

---

## 5. Rotate an API key from a CI job

Three-step rotation: create the new key, write it to your secret store, then revoke the old one. The order matters — you must revoke with a **different** key than the one making the call (the backend returns `400 VALIDATION_ERROR` if you try to revoke the key you're authenticated with).

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FloopFloop\Client;

function rotate(string $victimName): void {
    // Use a long-lived bootstrap key (stored as a CI secret) to do the
    // rotation. Don't use the key we're about to revoke — that hits
    // the self-revoke guard.
    $bootstrap = new Client(apiKey: getenv('FLOOP_BOOTSTRAP_KEY'));

    // 1. Find the key we want to rotate by its name. (Each name is
    //    unique per account because the dashboard enforces it;
    //    matching by name is more reliable than matching the prefix
    //    substring.)
    $keys = $bootstrap->apiKeys()->list();
    $victim = null;
    foreach ($keys as $k) {
        if ($k['name'] === $victimName) {
            $victim = $k;
            break;
        }
    }
    if ($victim === null) {
        throw new \RuntimeException("key not found: {$victimName}");
    }

    // 2. Mint the replacement.
    $fresh = $bootstrap->apiKeys()->create("{$victimName}-new");
    write_secret('FLOOP_API_KEY', $fresh['rawKey']);

    // 3. Revoke the old one. remove() accepts an id OR a name.
    $bootstrap->apiKeys()->remove($victim['id']);
}

// Wire write_secret into your CI's secret store — AWS Secrets
// Manager, HashiCorp Vault, GitHub Actions `gh secret set`, etc.
function write_secret(string $name, string $value): void {
    // ...
}
```

**Can't I just reuse the bootstrap key forever?** Technically yes — if it's tightly scoped and audited. In practice, a single long-lived "rotator key" is a common compromise: it only has permission to mint/list/revoke keys, never appears in application traffic, and itself gets rotated manually on a rare cadence (annually, or on compromise).

The 5-keys-per-account cap applies to active keys, so make sure to revoke old rotations rather than accumulating them.

---

## 6. Retry with backoff on `RATE_LIMITED` and `NETWORK_ERROR`

`FloopFloop\Error` carries everything you need to implement backoff correctly:

- `$e->retryAfter` — populated from the `Retry-After` header on 429s (parsed from delta-seconds OR HTTP-date), in **seconds** (float). `null` when the server didn't set it.
- `$e->code` — distinguishes retryable (`RATE_LIMITED`, `NETWORK_ERROR`, `TIMEOUT`, `SERVICE_UNAVAILABLE`, `SERVER_ERROR`) from permanent (`UNAUTHORIZED`, `FORBIDDEN`, `VALIDATION_ERROR`, `NOT_FOUND`, `CONFLICT`, `BUILD_FAILED`, `BUILD_CANCELLED`).

```php
<?php
use FloopFloop\Error;

const RETRYABLE = [
    'RATE_LIMITED',
    'NETWORK_ERROR',
    'TIMEOUT',
    'SERVICE_UNAVAILABLE',
    'SERVER_ERROR',
];

/**
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function withRetry(callable $fn, int $maxAttempts = 5): mixed {
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            return $fn();
        } catch (Error $e) {
            if (!in_array($e->code, RETRYABLE, true)) {
                throw $e;
            }
            if ($attempt >= $maxAttempts) {
                throw $e;
            }

            // Prefer the server's hint; fall back to exponential
            // backoff with jitter capped at 30 s.
            $serverHint = $e->retryAfter;
            $expo       = min(30.0, 0.25 * (2 ** $attempt));
            $jitter     = mt_rand() / mt_getrandmax() * 0.25;
            $wait       = ($serverHint ?? $expo) + $jitter;

            $reqTag = $e->requestId !== null ? " — request {$e->requestId}" : '';
            error_log(sprintf(
                "floop: %s (attempt %d/%d), retrying in %.2fs%s",
                $e->code, $attempt, $maxAttempts, $wait, $reqTag,
            ));
            usleep((int) ($wait * 1_000_000));
        }
    }
}

// Wrap any SDK call:
$projects = withRetry(fn () => $client->projects()->list());
```

**Don't retry everything.** `VALIDATION_ERROR`, `UNAUTHORIZED`, and `FORBIDDEN` are not going to fix themselves between attempts — retrying them just burns rate-limit budget and delays the real error reaching your logs.

**`retryAfter` is in seconds, not milliseconds** — this differs from the Node / Python SDKs (which expose `retryAfterMs` / `retry_after_ms`). Matches the Ruby SDK's convention.

---

## Got a pattern worth adding?

Open an issue at [FloopFloopAI/floop-php-sdk/issues](https://github.com/FloopFloopAI/floop-php-sdk/issues) describing the use case. Recipes live in this file, not in `src/`, so they're easy to update without a Composer release.
