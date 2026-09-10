<?php

declare(strict_types=1);

// Exercise the production autoloader with a guard that fails if any Laravel/Symfony class loads.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'Symfony\\')) {
        throw new RuntimeException('Core attempted to load a framework class: '.$class);
    }
}, true, true);
require dirname(__DIR__).'/vendor/autoload.php';

use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\HttpTransport;
use Botect\Http\Response;

$directory = sys_get_temp_dir().'/botect-plain-'.bin2hex(random_bytes(8));
$transport = new class implements HttpTransport
{
    public int $calls = 0;

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->calls++;

        return new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
    }
};
try {
    $sdk = Botect::create(new Configuration('pk_test', 'sk_secret'), $directory, $transport, delivery: 'spool');
    if (! $sdk->loggedIn('sess_test') || $transport->calls !== 0 || ! str_contains($sdk->collector(), 'data-site-key="pk_test"')) {
        throw new RuntimeException('Plain PHP request behavior failed.');
    }
    if ($sdk->flush()->sent !== 1 || $transport->calls !== 1) {
        throw new RuntimeException('Plain PHP worker behavior failed.');
    }
    $simple = Botect::create(new Configuration('pk_simple', 'sk_secret'), transport: $transport);
    if (! $simple->loggedIn('sess_simple') || $transport->calls !== 1 || $simple->sendPending()->sent !== 1 || $transport->calls !== 2) {
        throw new RuntimeException('Default deferred delivery failed.');
    }
    foreach (get_declared_classes() as $class) {
        if (str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'Symfony\\')) {
            throw new RuntimeException('A framework was loaded: '.$class);
        }
    }
    echo "Plain PHP integration passed without Laravel or Symfony.\n";
} finally {
    if (is_dir($directory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }
}
