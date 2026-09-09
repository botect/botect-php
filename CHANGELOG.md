# Changelog

Notable changes to `botect/botect-php` are documented here. This changelog covers the PHP package; see the [Botect platform changelog](https://docs.botect.ai/changelog) for API and platform updates.

## Unreleased

Initial SDK implementation. No versioned package release has been tagged yet.

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

- Installation currently uses the GitHub repository's `dev-main` branch; the package has not yet been published to Packagist.
- Server ingest, page tracking, and enforcement are disabled by default. Server-ingest features require a compatible Botect backend with those endpoints enabled.
- Only spool and queue delivery require a worker. On non-FPM hosts, the default shutdown fallback may delay the response. The default plain PHP verdict cache is request-local; use immediate lookups or configure a persistent cache for evidence across requests.
