<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Botect\Botect;
use Botect\Configuration;

// No storage directory or worker is needed for the default deferred delivery.
$botect = Botect::create(new Configuration(
    siteKey: (string) getenv('BOTECT_SITE_KEY'),
    privateKey: getenv('BOTECT_PRIVATE_KEY') ?: null,
));

// Browser delivery works with the current public API. The collector token currently lives in
// localStorage: pass it explicitly to your server when calling verdict() or loggedIn().
echo $botect->collector();

// $verdict = $botect->lookupVerdict($sessionToken); // Explicit immediate read, with a timeout.
// $queued = $botect->loggedIn($sessionToken); // No email, user ID, or request body.
// Writes send after response completion on PHP-FPM. No retries; non-FPM shutdown may delay the response.
// Optional: select delivery: "spool" and a private storageDirectory to use examples/worker.php.
