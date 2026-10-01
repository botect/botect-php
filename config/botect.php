<?php

declare(strict_types=1);
use Botect\Laravel\DefaultLoggedInResolver;
use Botect\Laravel\DefaultVerdictHandler;

return [
    'site_key' => env('BOTECT_SITE_KEY', ''),
    'private_key' => env('BOTECT_PRIVATE_KEY'),
    'api_url' => env('BOTECT_API_URL', 'https://api.botect.ai/v1'),
    'collector_url' => env('BOTECT_COLLECTOR_URL', 'https://cdn.botect.ai/v1/sdk.js'),

    // Enable only after the server-ingest backend contracts are deployed.
    'server_ingest_enabled' => (bool) env('BOTECT_SERVER_INGEST_ENABLED', false),
    'ingest_path' => env('BOTECT_INGEST_PATH', '/_botect/events'),
    // Which address to report as the visitor's with page hits and forwarded
    // events. `auto` uses the request address when it is public and otherwise
    // guesses from the headers common edges set, marking the result inferred
    // (weaker evidence) and logging once. Pin it once you know your setup:
    // `request` (trust your proxy configuration), `cloudflare`
    // (CF-Connecting-IP), `header:<Name>` (any header your edge overwrites),
    // or a class implementing Botect\Laravel\Contracts\ClientIpResolver.
    'client_ip' => env('BOTECT_CLIENT_IP', 'auto'),
    // Reports auth()->check() with tracked pages and verdicts. Set resolver to
    // null to omit the state, or name a class implementing
    // Botect\Laravel\Contracts\LoggedInResolver.
    'logged_in' => [
        'resolver' => DefaultLoggedInResolver::class,
    ],
    'cookie_name' => 'botect_server_session',
    'cookie_minutes' => 60 * 24 * 30,
    'page_token_ttl' => 900,
    'verdict_ttl' => 10,
    // Immediate lookups block a visitor request: keep these short.
    'connect_timeout_ms' => (int) env('BOTECT_CONNECT_TIMEOUT_MS', 200),
    'timeout_ms' => (int) env('BOTECT_TIMEOUT_MS', 1000),
    // Queue and spool deliveries run in a worker where nothing waits on them.
    // Deferred delivery holds a PHP-FPM worker after the response and keeps
    // the short limits above.
    'delivery_connect_timeout_ms' => (int) env('BOTECT_DELIVERY_CONNECT_TIMEOUT_MS', 1000),
    'delivery_timeout_ms' => (int) env('BOTECT_DELIVERY_TIMEOUT_MS', 5000),
    'max_body_bytes' => 262144,

    // deferred needs no worker; spool and queue are explicit durable options.
    'delivery' => env('BOTECT_DELIVERY', 'deferred'),
    'storage_path' => storage_path('app/private/botect'),
    'spool_capacity' => 1000,
    // Optional overrides; null inherits Laravel's connection and its default queue.
    'queue_connection' => env('BOTECT_QUEUE_CONNECTION'),
    'queue' => env('BOTECT_QUEUE'),
    'cache_store' => env('BOTECT_CACHE_STORE'),

    // Tracked responses carry a per-visitor page token and session cookie.
    // Never serve them from a CDN or full-page cache: a cached copy hands one
    // visitor's session to everyone who receives it. See the README.
    'tracking' => [
        'enabled' => false,
        // 'web' tracks every route in the web middleware group; 'manual' tracks
        // only routes that carry the botect.track middleware.
        'scope' => 'web',
        'inject_collector' => true,
        'except' => [],
    ],
    // Enforcement is opt-in through the botect.enforce middleware alias.
    'enforcement' => [
        'enabled' => false,
        'handler' => DefaultVerdictHandler::class,
    ],
];
