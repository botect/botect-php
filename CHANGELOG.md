# Changelog

Notable changes to `botect/botect-php` are documented here. This changelog covers the PHP package; see the [Botect platform changelog](https://docs.botect.ai/changelog) for API and platform updates.

## Unreleased

## v0.1.2 — 2026-09-11

### Added

- Separate timeouts for background deliveries: `deliveryConnectTimeoutMs` / `deliveryTimeoutMs` in plain PHP and `botect.delivery_connect_timeout_ms` / `botect.delivery_timeout_ms` (`BOTECT_DELIVERY_CONNECT_TIMEOUT_MS` / `BOTECT_DELIVERY_TIMEOUT_MS`) in Laravel, defaulting to 1,000 ms and 5,000 ms. Queue and spool deliveries run where nothing waits on them, and the previous 1,000 ms limit turned a slow Botect response into a retry and an error report. Immediate lookups keep the short lookup timeouts; deferred delivery keeps them too, because it holds a PHP-FPM worker after the response.
- `TimeoutAwareTransport`, an optional contract for custom transports that want the delivery limits. Transports implementing only `HttpTransport` keep working unchanged.

### Changed

- The lookup timeouts can be set from the environment in Laravel (`BOTECT_CONNECT_TIMEOUT_MS`, `BOTECT_TIMEOUT_MS`); the published configuration previously hardcoded them.

## v0.1.1 — 2026-09-10

### Added

- `forwardEvents()` accepts the visitor's signed session cookie as a fourth argument and throws `SessionMismatchException` when the page token names another session. The Laravel ingest endpoint reads the cookie (encrypted or plain), answers HTTP 409 instead of attributing the batch, and logs one warning per page token with the page id and referring path. Missing or unverifiable cookies are accepted as before. Requires a collector that sends same-origin credentials; older cached collectors keep the previous behavior.
- `tracking.scope` in the Laravel configuration. `web` (the default) adds tracking to every route in the `web` middleware group, as before; `manual` tracks only routes that carry the `botect.track` middleware, so a site that caches most of its pages can track just the routes that are never cached.

### Changed

- The README now states at the start of the server-tracking section that tracked pages must not be served from a CDN or full-page cache, and what goes wrong when they are. It also describes the 409 rejection above.
- The Laravel ingest endpoint's 422 response now carries `Cache-Control: no-store` like its other responses.

### Fixed

- Server tracking now stays registered when another package changes the HTTP kernel's middleware after the SDK boots. Laravel Sanctum does this during its own boot, which previously removed tracking from the `web` group without an error: pages received no collector, no session cookie, and no server page observations. Applications that added `TrackPage` to their own `web` group as a workaround can keep it; the middleware is not registered twice.

## v0.1.0 — 2026-09-09

Initial public SDK release.

### Changed

- Laravel queue delivery inherits the application's default connection and its configured default queue. `BOTECT_QUEUE_CONNECTION` and `BOTECT_QUEUE` remain optional overrides.

- Default delivery is now `deferred` in plain PHP and Laravel. Requests are buffered in memory and attempted once after response completion on PHP-FPM, without a worker, persistent spool, or retries.
- Plain PHP `Botect::create()` no longer requires a storage directory. Select `delivery: 'spool'` explicitly to retain the previous file-spool behavior; supplying a directory alone enables file verdict caching.
- Laravel buffers are scoped to the request and drained through the application-termination hook. File-spool and asynchronous queue delivery remain opt-in.

### Added

- Explicit `lookupVerdict()` for immediate, timeout-bounded verdict reads, separate from cached `verdict()` reads and deferred refreshes.
- Bounded deferred buffering, duplicate suppression, a drain budget, and `sendPending()` for custom plain PHP request lifecycles.
- Lifecycle tests for failure handling, repeated Laravel requests, non-FPM shutdown behavior, and actual PHP-FPM response completion.

- Framework-independent PHP client supporting PHP 8.3 and later, with an optional Laravel 12 and 13 integration in the same Composer package.
- Browser collector rendering with project site keys, configurable API and collector URLs, and support for Content Security Policy nonces.
- Typed session verdicts, local verdict caching, and background refreshes. Unavailable verdict evidence defaults to allowing the request.
- Queued logged-in session assertions that invalidate the local verdict cache without sending application user IDs or email addresses.
- Optional server page observations, signed page tokens and session cookies, and browser event forwarding through an application's local ingest endpoint.
- File-based delivery spool with bounded capacity, duplicate suppression, retries, and an explicit CLI/worker flush entry point.
- Laravel service-provider discovery, publishable configuration, dependency injection, a facade, and the `@botect` Blade directive.
- Laravel asynchronous queue delivery and the `botect:flush` command for file-spool delivery.
- Optional Laravel page-tracking middleware with collector injection and private, non-cacheable HTML responses.
- Optional Laravel verdict enforcement, with default block and delay responses and a configurable verdict handler for application-specific behavior.
- Extensible HTTP transport, dispatcher, and verdict-cache contracts, plus testing fakes.
- Plain PHP, worker, and Laravel examples; a [README](README.md) with installation, setup, configuration, and documentation links.
- Unit and Laravel integration tests, a standalone framework-independence check, and a CI matrix for PHP 8.3–8.5 and Laravel 12–13, excluding Laravel 13 on PHP 8.3.

### Availability

- The `v0.1.0` release is available from GitHub. Packagist publication is pending.
- Server ingest, page tracking, and enforcement are disabled by default. Server-ingest features require a compatible Botect backend with those endpoints enabled.
- Only spool and queue delivery require a worker. On non-FPM hosts, the default shutdown fallback may delay the response. The default plain PHP verdict cache is request-local; use immediate lookups or configure a persistent cache for evidence across requests.
