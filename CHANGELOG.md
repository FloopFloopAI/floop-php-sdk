# Changelog

All notable changes to `floopfloop/sdk` will be documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-alpha.4] — 2026-04-28

### Added
- **`$client->subscriptions()->current()`** — new resource accessor that
  wraps `GET /api/v1/subscriptions/current` and returns the authenticated
  user's plan + credit-balance snapshot. Distinct from
  `$client->usage()->summary()` — `usage()->summary()` covers
  current-period consumption (credits remaining, builds used, storage),
  while `subscriptions()->current()` returns the plan tier itself (price,
  billing period, cancel state). They overlap on `monthlyCredits` and
  `maxProjects` but serve different audiences ("am I about to hit my
  limits?" vs "what plan am I on, and when does it renew?").
- Returns the full `array{subscription: array|null, credits: array|null}`
  shape — both keys are independently nullable: a user may exist without
  an active subscription (mid-signup, cancelled with no grace credits).

### Changed
- `Client::VERSION` bumped to `0.1.0-alpha.4`.

### Tests
- Two new cases in `tests/ResourcesTest.php` covering the populated
  response and the both-null edge case.

### Notes
- Mirrors [`@floopfloop/sdk` PR #6](https://github.com/FloopFloopAI/floop-node-sdk/pull/6)
  (Node `0.1.0-alpha.3`) — cross-SDK parity drop.

## [0.1.0-alpha.3] — 2026-04-26

### Fixed
- **`Projects::stream` and `Projects::waitForLive` looped until
  `maxWait` when a project entered the `archived` state mid-stream.**
  The if-chain only matched `live` / `failed` / `cancelled`;
  `archived` fell through and polling continued. Now
  `if ($status === 'live' || $status === 'archived')` returns the
  event — matches Node, Python, Swift, and Kotlin which already treat
  archived as a non-error terminal. `TERMINAL_STATUSES` constant
  updated to match.
- Cross-SDK parity audit. Same drift fixed the same day in floop-go-sdk
  alpha.5, floop-rust-sdk alpha.3, and floop-ruby-sdk alpha.2.
- New `tests/StreamTest.php#test_archived_terminates_cleanly_like_live`
  locks in the regression.

### Changed
- `Client::VERSION` bumped to `0.1.0-alpha.3`.

## [0.1.0-alpha.2] — 2026-04-26

### Fixed
- **`StreamClient` (production-default HTTP transport) was broken on
  every successful response.** `HttpClient.php` read response headers
  via `$GLOBALS['http_response_header']`, but PHP's stream wrapper
  populates `$http_response_header` in the *local* scope of the
  function that calls `file_get_contents` — never `$GLOBALS`. Result:
  `$rawHeaders` was always `[]` and the next line threw
  `NETWORK_ERROR: no response headers captured from <url>` for every
  alpha.1 user that didn't inject their own `HttpClient`. The bug
  escaped CI because `tests/TransportTest.php` injects
  `FakeHttpClient` which bypasses the stream wrapper entirely.

### Added
- **`tests/RealHttpClientTest.php`** — end-to-end coverage that boots
  `php -S` on an ephemeral port, drives `StreamClient` against it, and
  asserts response headers parse + the typed error envelope round-trips.
  Locks in the regression. Adds an `tests/fixtures/server.php` mini
  router. Three new tests.

## [0.1.0-alpha.1] — 2026-04-24

First public release. Full parity with the Node, Python, Go, Rust, and Ruby SDKs.

### Added

- `FloopFloop\Client` — main entry point. Construct with `apiKey` (required) plus optional `baseUrl`, `timeout`, `userAgentSuffix`, `httpClient`.
- Resource accessors: `projects()`, `subdomains()`, `secrets()`, `library()`, `usage()`, `apiKeys()`, `uploads()`, `user()`.
- `projects()` — `create`, `list`, `get`, `status`, `cancel`, `reactivate`, `refine`, `conversations`, `stream` (poll loop with consecutive-snapshot de-dup), `waitForLive`.
- `uploads()->create()` — two-step flow: presign against `/api/v1/uploads`, then direct `PUT` to the returned S3 URL.
- `FloopFloop\Error` — single exception type. Exposes `$code` (string), `$status` (int), `$requestId` (?string), `$retryAfter` (?float). `Retry-After` parses both delta-seconds and HTTP-date (past-date → `0.0`). Unknown server codes pass through verbatim.
- `FloopFloop\HttpClient` — interface for transport. Default `StreamClient` uses PHP stdlib `file_get_contents` + `stream_context_create` (no `ext-curl` required).
- Offline PHPUnit test suite against an in-memory `FakeHttpClient`. 38 tests / 89 assertions.

[0.1.0-alpha.3]: https://github.com/FloopFloopAI/floop-php-sdk/releases/tag/v0.1.0-alpha.3
[0.1.0-alpha.2]: https://github.com/FloopFloopAI/floop-php-sdk/releases/tag/v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/FloopFloopAI/floop-php-sdk/releases/tag/v0.1.0-alpha.1
