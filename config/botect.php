<?php

declare(strict_types=1);
use Botect\Laravel\DefaultVerdictHandler;

return [
    'site_key' => env('BOTECT_SITE_KEY', ''),
    'private_key' => env('BOTECT_PRIVATE_KEY'),
    'api_url' => env('BOTECT_API_URL', 'https://api.botect.ai/v1'),
    'collector_url' => env('BOTECT_COLLECTOR_URL', 'https://cdn.botect.ai/v1/sdk.js'),

    // Enable only after the server-ingest backend contracts are deployed.
    'server_ingest_enabled' => (bool) env('BOTECT_SERVER_INGEST_ENABLED', false),
    'ingest_path' => env('BOTECT_INGEST_PATH', '/_botect/events'),
    'cookie_name' => 'botect_server_session',
    'cookie_minutes' => 60 * 24 * 30,
    'page_token_ttl' => 900,
    'verdict_ttl' => 10,
    'connect_timeout_ms' => 200,
    'timeout_ms' => 1000,
    'max_body_bytes' => 262144,

    // Spool requires botect:flush in a background worker/cron. Never under public/.
    'delivery' => env('BOTECT_DELIVERY', 'spool'),
    'storage_path' => storage_path('app/private/botect'),
    'spool_capacity' => 1000,
    'queue_connection' => env('BOTECT_QUEUE_CONNECTION'),
    'queue' => env('BOTECT_QUEUE', 'botect'),
    'cache_store' => env('BOTECT_CACHE_STORE'),

    'tracking' => [
        'enabled' => false,
        'inject_collector' => true,
        'except' => [],
    ],
    // Enforcement is opt-in through the botect.enforce middleware alias.
    'enforcement' => [
        'enabled' => false,
        'handler' => DefaultVerdictHandler::class,
    ],
];
