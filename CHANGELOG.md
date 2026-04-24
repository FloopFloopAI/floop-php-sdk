# Changelog

All notable changes to `floopfloop/sdk` will be documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.1.0-alpha.1]: https://github.com/FloopFloopAI/floop-php-sdk/releases/tag/v0.1.0-alpha.1
