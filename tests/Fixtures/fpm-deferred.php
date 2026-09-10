<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\HttpTransport;
use Botect\Http\Response;

$marker = $_SERVER['BOTECT_TEST_MARKER'];
$transport = new class($marker) implements HttpTransport
{
    public function __construct(private string $marker) {}

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        file_put_contents($this->marker.'.started', 'started');
        $deadline = microtime(true) + 5;
        while (! is_file($this->marker.'.release') && microtime(true) < $deadline) {
            usleep(10000);
        }
        file_put_contents($this->marker.'.tmp', session_status() === PHP_SESSION_ACTIVE ? 'session-locked' : 'delivered');
        rename($this->marker.'.tmp', $this->marker);

        return new Response(200, '{"logged_in":true,"asserted_at":"2026-09-09T00:00:00Z"}');
    }
};

session_save_path(dirname($marker));
session_start();
$_SESSION['test'] = 'saved';
$botect = Botect::create(new Configuration('pk_test', 'sk_secret'), transport: $transport);
$botect->loggedIn('sess_test');
register_shutdown_function(static function (): void {
    echo '|APP-SHUTDOWN';
});
echo 'RESPONSE';
