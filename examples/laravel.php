<?php

declare(strict_types=1);

use Botect\Laravel\Facades\Botect;
use Illuminate\Support\Facades\Schedule;

// Install this same package; Laravel discovers its provider automatically.
// Set BOTECT_SITE_KEY and BOTECT_PRIVATE_KEY, then publish config:
// php artisan vendor:publish --tag=botect-config
// In Blade: @botect (or @botect($cspNonce)).

// Run the spool worker separately. For lower latency choose BOTECT_DELIVERY=queue
// and a real async connection, then run queue:work --queue=botect.
Schedule::command('botect:flush --limit=100')->everyMinute()->withoutOverlapping()->runInBackground();

// After successful login: Botect::loggedIn($sessionToken).
// Current collector tokens must be explicitly passed to the application by the browser.
// Once server ingest is deployed, tracking sets a signed HttpOnly server cookie instead.
// Resolve it with Botect::sessionToken(request()->cookie(config('botect.cookie_name'), '')).

// Server page tracking / proxy are intentionally gated until both backend subtasks ship:
// https://app.clickup.com/t/86e35ej57 (server ingest), https://app.clickup.com/t/86e35ej5b (correlation).
// Set server_ingest_enabled and tracking.enabled only against a compatible backend.
// Tracking personalizes HTML: bypass full-page/CDN caches, and provide a csp_nonce request
// attribute or render @botect($nonce) yourself if the application uses a strict CSP.
// The registered ingest route uses a signed page token, outside the web/CSRF middleware group.

// Opt in to enforcement with enforcement.enabled and route middleware('botect.enforce').
// Block returns 403; delay returns 429 without sleeping. Challenge allows by default until
// a custom VerdictHandler connects your application's actual challenge flow.
// Local verdicts expire in 10 seconds. A miss schedules refresh and allows the request.
