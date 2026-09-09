<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Botect\Botect;
use Botect\Configuration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$botect = Botect::create(new Configuration(
    siteKey: (string) getenv('BOTECT_SITE_KEY'),
    privateKey: getenv('BOTECT_PRIVATE_KEY') ?: null,
    serverIngestEnabled: filter_var(getenv('BOTECT_SERVER_INGEST_ENABLED') ?: false, FILTER_VALIDATE_BOOL),
), (string) (getenv('BOTECT_STORAGE') ?: sys_get_temp_dir().'/botect-example'), delivery: 'spool');
$result = $botect->flush();
printf("Sent: %d; retried: %d; failed: %d\n", $result->sent, $result->retried, $result->failed);
exit($result->failed > 0 ? 1 : 0);
