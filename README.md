# Botect PHP SDK

The official PHP SDK for [Botect](https://www.botect.ai), a bot-detection platform that scores visitor sessions and helps your application decide how to handle automated traffic.

Use the same package in **plain PHP** or **Laravel**. The core works without a framework; Laravel adds automatic service discovery, a facade, Blade integration, middleware, configuration publishing, and queue support.

[Documentation](https://docs.botect.ai) · [Changelog](CHANGELOG.md) · [Quickstart](https://docs.botect.ai/quickstart) · [Authentication](https://docs.botect.ai/authentication) · [Report an issue](https://github.com/botect/botect-php/issues)

## Choose a delivery mode

**Start with `deferred` (the default).** It needs no queue worker, cron job, or delivery files. Choose `spool` or `queue` when you want persistent delivery with retries.

These are the exact supported configuration values:

| Value | Plain PHP | Laravel | How it sends | Required setup | Retries |
| --- | --- | --- | --- | --- | --- |
| **`deferred`** — default | Yes | Yes | Buffers in memory and sends after the response on PHP-FPM | None; PHP-FPM recommended | No; one attempt |
| **`spool`** | Yes | Yes | Saves deliveries to files for a background process to send | Private writable directory and a cron job or flush worker | Yes; bounded retries |
| **`queue`** | No built-in driver | Yes | Dispatches deliveries to Laravel's asynchronous queue | Configured queue connection and running queue worker | Yes; bounded retries |

**Where to set it:**

| Environment | Configuration location | Example |
| --- | --- | --- |
| Plain PHP | `delivery` argument of `Botect::create()` | `Botect::create($configuration, delivery: 'deferred')` |
| Laravel | `BOTECT_DELIVERY` in `.env`, mapped to `botect.delivery` | `BOTECT_DELIVERY=deferred` |

For plain PHP `spool` mode, also pass `storageDirectory`. In Laravel, `BOTECT_DELIVERY=queue` inherits the application's default connection and that connection's default queue from `config/queue.php`. Leave `BOTECT_QUEUE_CONNECTION` and `BOTECT_QUEUE` unset unless you want to override them. The synchronous `sync` connection is not supported.

On hosts without PHP-FPM's `fastcgi_finish_request()`, `deferred` falls back to shutdown processing and **may delay the browser response**. Even on PHP-FPM, sending still occupies a PHP worker briefly. Buffered deliveries are not persisted in this mode.

This setting controls server deliveries and cached-verdict refreshes. Browser events sent directly to Botect bypass it; `lookupVerdict()` always makes an immediate request and waits for a response.

**Setup guides:** [Plain PHP](#plain-php-quickstart) · [Laravel](#laravel-quickstart) · [Configuration reference](#configuration-reference)

## What the SDK does

- Renders the Botect browser collector with your project's site key.
- Reads cached session verdicts and schedules background refreshes.
- Queues assertions that a visitor has authenticated in your application.
- Supports optional server page tracking and forwarding browser events through your application.
- Sends server requests after the browser response by default, with optional file-spool and Laravel queue delivery.
- Allows requests when verdict evidence is unavailable. Enforcement is opt-in.

The browser collector gathers signals; Botect's API computes scores. This package integrates those capabilities into your PHP application. It does not implement the scoring engine or wrap every Botect management API.

## Requirements

| Environment | Requirements |
| --- | --- |
| Plain PHP | PHP 8.3+, JSON, and cURL for the default HTTP transport |
| Laravel | Laravel 12 with PHP 8.3+, or Laravel 13 with PHP 8.4+ |

Enable scoring for a project in Botect to obtain its **site key** (`pk_…`) and **private key** (`sk_…`). The site key can appear in browser markup. Keep the private key on your server; it is required for verdicts, logged-in assertions, and server ingest. An account API token is a different credential—see [Authentication](https://docs.botect.ai/authentication).

## Installation

The package is currently available from GitHub as a development version. Until it is published to Packagist, add the repository to your application's Composer configuration:

```bash
composer config repositories.botect vcs https://github.com/botect/botect-php.git
composer require botect/botect-php:dev-main
```

Commit your application's `composer.lock` to keep deployments on the same revision. Laravel support is included in this package; no separate Laravel package is needed.

## Plain PHP quickstart

### 1. Configure the client

Create `botect.php` in your application root, outside the public directory:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Botect\Botect;
use Botect\Configuration;

return Botect::create(
    new Configuration(
        siteKey: (string) getenv('BOTECT_SITE_KEY'),
        privateKey: getenv('BOTECT_PRIVATE_KEY') ?: null,
    ),
);
```

Provide the environment variables through your application's environment or existing configuration loader. The SDK does not load `.env` files itself. The default delivery mode does not require a storage directory or background worker.

### 2. Add the browser collector

In your page template, render the collector once, before `</head>` or `</body>`:

```php
<?php $botect = require dirname(__DIR__).'/botect.php'; ?>
<!doctype html>
<html>
<head>
    <title>My application</title>
    <?= $botect->collector() ?>
</head>
<body>
    <!-- Your page content -->
</body>
</html>
```

By default, the collector loads from `https://cdn.botect.ai/v1/sdk.js` and sends browser events directly to `https://api.botect.ai/v1/events`.

For a Content Security Policy nonce, use `$botect->collector(cspNonce: $nonce)`. Your policy must also permit the collector's script source and event destination.

### 3. Let the SDK send after the response

This quickstart uses [`deferred`](#choose-a-delivery-mode). No additional worker setup is needed. When you call `loggedIn()`, `recordPage()`, or `forwardEvents()`, the SDK buffers the delivery in memory. At the end of the request, it releases an active PHP session lock and calls `fastcgi_finish_request()` when available, then attempts each buffered delivery once.

On PHP-FPM, the browser receives the completed response before delivery starts. The PHP worker remains occupied briefly while sending. Failed deliveries are discarded: this mode does not persist or retry them, and a killed process can lose pending deliveries.

On hosts without `fastcgi_finish_request()`, the fallback runs during PHP shutdown and **may delay the browser response**. In long-running plain PHP processes, call `$botect->sendPending()` at the end of each request or unit of work, after your host has sent its response. PHP shutdown happens only when the process exits.

The buffer accepts up to 10 distinct deliveries and 1 MiB of serialized data per drain. A 1,000 ms budget is checked between attempts; an attempt already in progress can run until its configured HTTP timeout. Remaining deliveries are discarded when the budget is exhausted. Calls return `false` if a delivery cannot fit in the buffer.

### Optional: file-spool delivery

For persistence and retries, opt into the existing file-spool mode:

```php
$botect = Botect::create(
    new Configuration(
        siteKey: (string) getenv('BOTECT_SITE_KEY'),
        privateKey: getenv('BOTECT_PRIVATE_KEY') ?: null,
    ),
    storageDirectory: __DIR__.'/var/botect',
    delivery: 'spool',
);
```

Keep that directory outside the public web root. The web application and worker must use the same configuration and storage directory. Run `$botect->flush(limit: 100)` from a CLI worker or cron; see the [worker example](examples/worker.php). Never flush the file spool during a visitor's request. Browser events sent directly to Botect do not use either delivery buffer.

## Laravel quickstart

### 1. Publish configuration

Laravel discovers the service provider automatically:

```bash
php artisan vendor:publish --tag=botect-config
```

Set your project's keys in `.env`:

```dotenv
BOTECT_SITE_KEY=pk_your_site_key
BOTECT_PRIVATE_KEY=sk_your_private_key
```

The complete configuration is in [`config/botect.php`](config/botect.php). Tracking and enforcement are disabled by default.

### 2. Add the collector to Blade

Place the directive once in your layout:

```blade
<head>
    @botect
</head>
```

For a CSP nonce, use `@botect($nonce)`.

The client is also available through the container as `Botect\Botect` and through the `Botect\Laravel\Facades\Botect` facade.

### 3. Set up your chosen delivery mode

Use the [delivery-mode table](#choose-a-delivery-mode) to choose a value for `BOTECT_DELIVERY`. The setup for each mode follows.

**Deferred (default):** nothing else to configure. Laravel drains the in-memory delivery buffer through its application-termination hook after the response is sent. No Laravel queue connection or worker is required. The same best-effort limits and non-FPM caveat described above apply.

```dotenv
BOTECT_DELIVERY=deferred
```

**File spool:** set `BOTECT_DELIVERY=spool`, add this to `routes/console.php`, and run your application's Laravel scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('botect:flush --limit=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

Spool files are stored under `storage/app/private/botect`, separated by project and API URL. Each local spool needs a worker that can access it. Schedule more frequent flushing if you need prompt verdict refreshes.

**Asynchronous queue:** use your application's existing Laravel queue setup by setting just:

```dotenv
BOTECT_DELIVERY=queue
```

The SDK uses `queue.default` (normally configured by Laravel's `QUEUE_CONNECTION`) and the default queue configured for that connection in `config/queue.php`. Your existing workers process Botect jobs alongside application jobs; no separate Botect queue or worker is required. Those workers must consume the selected connection's default queue.

The `sync` driver is not supported for queue delivery; use `deferred` when you do not have an asynchronous queue worker. With queue delivery, use your existing worker instead of `botect:flush`.

#### Optional queue customization

Set these only if you deliberately want different routing for Botect jobs:

| Optional environment variable | Purpose | When unset |
| --- | --- | --- |
| `BOTECT_QUEUE_CONNECTION` | Select a different configured Laravel connection | Uses `queue.default` |
| `BOTECT_QUEUE` | Select a different queue on that connection | Uses the connection's configured default queue |

For example, to use an existing Redis connection with a dedicated queue:

```dotenv
BOTECT_QUEUE_CONNECTION=redis
BOTECT_QUEUE=botect
```

Both overrides are independent. If you set either, make sure a worker consumes the selected connection and queue.

Laravel verdicts use your configured cache store. Web processes and queue workers must share that cache for background refreshes to be useful; set `BOTECT_CACHE_STORE` when needed.

## Reading verdicts

A verdict needs a response from Botect, so the SDK provides two explicit choices.

### Immediate lookup

Use `lookupVerdict()` when you need evidence for the current request:

```php
$verdict = $botect->lookupVerdict($sessionToken, ['path' => '/checkout']);

if ($verdict->available()) {
    $score = $verdict->score;
    $action = $verdict->action;
    $reason = $verdict->reason;
}
```

In Laravel:

```php
use Botect\Laravel\Facades\Botect;

$verdict = Botect::lookupVerdict($sessionToken);
```

This method makes one immediate HTTP request and waits up to the configured timeout. It does not retry or buffer a refresh. Invalid input, a missing private key, network failures, and unavailable evidence return an allow verdict.

### Cached lookup

`$botect->verdict($sessionToken)` reads cached evidence without making an inline HTTP request. On a miss, it schedules a refresh through your chosen delivery driver and returns `action: allow`, `verdict: not_computed`, and `score: 0`. Cached verdicts expire after 10 seconds by default.

**The default plain PHP cache exists only for the current request.** A refresh after the response cannot benefit the next request unless you configure a persistent cache. Use `lookupVerdict()` for the simplest plain PHP integration. For cached lookups, pass a private `storageDirectory` to `Botect::create()` to enable the file verdict cache without changing the delivery mode, or supply your own `VerdictCache` implementation through the `cache` argument. For the optional file verdict cache, periodically call `$botect->pruneVerdictCache()` from CLI to remove expired files; spool `flush()` already does this. No cache maintenance is needed for the default request-local cache. Laravel uses its configured cache store automatically.

Both lookup methods accept the context keys `path`, `ip`, `country`, and `ua`; values must be strings. Cached lookups use separate entries for different contexts.

In direct browser mode, the collector's session token lives in browser local storage. Your application must explicitly pass that token to its server when using verdicts or logged-in assertions; the SDK does not discover it from a PHP session. With server tracking enabled, use the signed session cookie described below.

See [Verdict API](https://docs.botect.ai/endpoints/verdict) and [Score bands](https://docs.botect.ai/score-bands) for response fields and scoring behavior.

## Marking a visitor as logged in

After your application has authenticated a visitor, schedule an assertion for their Botect session:

```php
$queued = $botect->loggedIn($sessionToken);
```

Or with the Laravel facade:

```php
use Botect\Laravel\Facades\Botect;

$queued = Botect::loggedIn($sessionToken);
```

A `true` result means the configured dispatcher accepted the assertion, not that Botect has accepted it yet. The default mode sends it after the response; spool and queue modes use their workers. The SDK invalidates the local verdict cache for that session and sends no application user ID or email address with the assertion.

See [Logged-in visitors](https://docs.botect.ai/logged-in-visitors) for how assertions interact with your rules.

## Optional server tracking

Server tracking records page observations and routes browser events through an endpoint on your application. Enable it only when your Botect backend supports and has enabled the server-ingest endpoints. It is disabled by default.

**Tracked pages must not be cached.** Every tracked response carries a page token and a session cookie minted for one visitor, and is sent with `Cache-Control: private, no-store`. A CDN or full-page cache that stores a tracked response anyway serves that visitor's token to everyone who receives the cached copy: their browser events are attributed to the first visitor's session, and once the token expires (15 minutes) they are rejected. Neither failure raises an error. Exclude cached pages with `tracking.except`, or track only specific routes with `tracking.scope` (below).

For Laravel, set:

```dotenv
BOTECT_SERVER_INGEST_ENABLED=true
```

Then change the existing tracking settings in `config/botect.php`:

```php
'tracking' => [
    'enabled' => true,
    'scope' => 'web',
    'inject_collector' => true,
    'except' => ['admin/*'],
],
```

With `scope` set to `web`, the provider adds tracking to the `web` middleware group and registers `POST /_botect/events`. Eligible successful HTML GET responses receive a signed HttpOnly session cookie and a collector configured to use that local endpoint. Browser forwarding and page observations use the configured delivery mode.

Tracking injects the collector automatically. If you render `@botect` yourself, set `tracking.inject_collector` to `false`. For automatic injection under a nonce-based CSP, supply a `csp_nonce` request attribute.

Rebuild Laravel's configuration and route caches after changing the server-ingest setting, the tracking settings, or the ingest path.

### Tracking only some routes

Sites that cache most of their pages should track only the routes that are never cached, such as sign-in, search, and account pages. Set `scope` to `manual` and attach the `botect.track` middleware to those routes:

```php
'tracking' => [
    'enabled' => true,
    'scope' => 'manual',
],
```

```php
Route::get('/search', SearchController::class)->middleware('botect.track');
```

No other route is tracked. `tracking.except` still applies to the routes you attach it to, and the ingest endpoint is registered in both scopes. Keep `@botect` in your layout: on tracked routes it renders the collector bound to that visitor's page token, and everywhere else it renders the standard collector that reports directly to Botect, which is safe to cache.

Resolve a session token from the signed cookie in Laravel:

```php
use Botect\Laravel\Facades\Botect;

$sessionToken = Botect::sessionToken(
    (string) request()->cookie(config('botect.cookie_name'), ''),
);
```

An absent or invalid cookie returns `null`; check for a token before calling `loggedIn()` or `verdict()`.

Plain PHP applications can build the same integration using `page()`, `sessionCookie()`, `recordPage()`, `collector($page)`, and `forwardEvents()`. Set `serverIngestEnabled: true` in `Configuration`, and implement the local POST handler at `ingestPath` to pass the signed page token and decoded collector body to `forwardEvents()`. The plain PHP core does not register routes or set cookies for you.

## Optional Laravel enforcement

Enable `enforcement.enabled` in `config/botect.php`, then apply the middleware to the routes you want to protect:

```php
use Illuminate\Support\Facades\Route;

Route::get('/checkout', CheckoutController::class)
    ->middleware('botect.enforce');
```

Use your application's controller. The middleware uses cached `verdict()` reads, so choose a persistent Laravel cache store for evidence to survive between requests. It needs a valid Botect server session cookie or a `botect.session_token` request attribute supplied by your integration; adding it alone does not connect a direct browser session to Laravel.

The default handler returns HTTP 403 for `block` and HTTP 429 with `Retry-After: 5` for `delay`. It allows `allow`, `log`, `challenge`, and unavailable evidence. To implement an actual challenge flow or different responses, provide a class implementing [`Botect\Laravel\Contracts\VerdictHandler`](src/Laravel/Contracts/VerdictHandler.php) and configure `enforcement.handler` to use it.

## Configuration reference

| Setting | Plain PHP constructor argument | Laravel configuration | Default |
| --- | --- | --- | --- |
| API base URL | `apiUrl` | `botect.api_url` / `BOTECT_API_URL` | `https://api.botect.ai/v1` |
| Collector URL | `collectorUrl` | `botect.collector_url` / `BOTECT_COLLECTOR_URL` | `https://cdn.botect.ai/v1/sdk.js` |
| Server ingest | `serverIngestEnabled` | `botect.server_ingest_enabled` / `BOTECT_SERVER_INGEST_ENABLED` | `false` |
| Local ingest path | `ingestPath` | `botect.ingest_path` / `BOTECT_INGEST_PATH` | `/_botect/events` |
| Connection timeout | `connectTimeoutMs` | `botect.connect_timeout_ms` | 200 ms |
| Request timeout | `timeoutMs` | `botect.timeout_ms` | 1,000 ms |
| Verdict cache lifetime | `verdictTtl` | `botect.verdict_ttl` | 10 seconds |
| Signed page token lifetime | `pageTokenTtl` | `botect.page_token_ttl` | 900 seconds |
| Maximum forwarded body size | `maxBodyBytes` | `botect.max_body_bytes` | 262,144 bytes |

API and collector URLs must be absolute HTTPS URLs. See [`Configuration`](src/Configuration.php) for validation limits and [`config/botect.php`](config/botect.php) for Laravel delivery, cache, cookie, and tracking settings.

Delivery values, defaults, and configuration locations are listed in [Choose a delivery mode](#choose-a-delivery-mode). Supplying a storage directory alone enables file verdict caching; it does not select spool delivery.

For custom infrastructure, the core accepts implementations of [`Dispatcher`](src/Contracts/Dispatcher.php), [`VerdictCache`](src/Contracts/VerdictCache.php), and [`HttpTransport`](src/Contracts/HttpTransport.php). Laravel applications can bind those contracts in their own service provider.

## Documentation and examples

- [Botect documentation](https://docs.botect.ai) — product guides and API reference.
- [Quickstart](https://docs.botect.ai/quickstart) — create a project and start collecting signals.
- [Authentication](https://docs.botect.ai/authentication) — site keys, private keys, and account tokens.
- [How scoring works](https://docs.botect.ai/how-scoring-works) — the scoring model.
- [Rules](https://docs.botect.ai/rules) — configure how Botect handles visitors.
- [Cloudflare enforcement](https://docs.botect.ai/cloudflare-enforcement) — enforce decisions at the edge.
- [Plain PHP example](examples/plain-php.php), [worker example](examples/worker.php), and [Laravel example](examples/laravel.php).

## Development

```bash
composer install
composer check
```

`composer check` runs the test suite and a standalone integration check that rejects any Laravel or Symfony class loading in the plain PHP core. The CI matrix covers PHP 8.3–8.5 and Laravel 12–13, excluding Laravel 13 on PHP 8.3.

Run `php tests/fpm.php` for an isolated PHP-FPM smoke test that verifies response completion before delivery. Set `PHP_FPM_BINARY` if the binary is not at the default system path.

Report SDK bugs and feature requests in [GitHub Issues](https://github.com/botect/botect-php/issues). Include your PHP/Laravel versions and a minimal reproduction, with credentials removed.

## Contributing

See [contributing.md](contributing.md) for setup, testing, and the pull request workflow.

## License

This package is available under the [MIT license](license.md).
