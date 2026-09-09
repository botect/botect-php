<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Botect\Botect;
use Botect\Configuration;

// Keep this directory outside the web root. The same configuration/storage must be used by the worker.
$botect = Botect::create(new Configuration(
    siteKey: (string) getenv('BOTECT_SITE_KEY'),
    privateKey: getenv('BOTECT_PRIVATE_KEY') ?: null,
), (string) (getenv('BOTECT_STORAGE') ?: sys_get_temp_dir().'/botect-example'));

// Browser delivery works with the current public API. The collector token currently lives in
// localStorage: pass it explicitly to your server when calling verdict() or loggedIn().
echo $botect->collector();

// $verdict = $botect->verdict($sessionToken); // Cached evidence, or allow while refresh is queued.
// $queued = $botect->loggedIn($sessionToken); // No email, user ID, or request body.
// In a separate CLI worker/cron: $botect->flush(); Never flush on the HTTP request path.
