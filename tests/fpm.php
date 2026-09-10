<?php

declare(strict_types=1);

// Isolated real-FPM smoke test. No web server or external API is involved.
$binary = getenv('PHP_FPM_BINARY') ?: '/usr/sbin/php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
if (! is_executable($binary)) {
    echo "FPM smoke test skipped; set PHP_FPM_BINARY to enable it.\n";
    exit;
}
$directory = sys_get_temp_dir().'/botect-fpm-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = $directory.'/fpm.sock';
$marker = $directory.'/sent';
$user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
file_put_contents($directory.'/fpm.conf', "[global]\nerror_log = {$directory}/error.log\ndaemonize = no\n[botect]\nuser = {$user}\nlisten = {$socket}\npm = static\npm.max_children = 1\ncatch_workers_output = yes\n");
$process = proc_open([$binary, '-F', '-R', '-y', $directory.'/fpm.conf'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/stdout', 'w'], 2 => ['file', $directory.'/stderr', 'w']], $pipes);
if (! is_resource($process)) {
    throw new RuntimeException('Unable to start isolated PHP-FPM.');
}

function fcgiRecord(int $type, string $body): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}

function fcgiLength(int $length): string
{
    return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
}

/** @param resource $stream */
function fcgiRead($stream, int $length): string
{
    $result = '';
    while (strlen($result) < $length) {
        $chunk = fread($stream, $length - strlen($result));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete FastCGI response.');
        }
        $result .= $chunk;
    }

    return $result;
}

try {
    $deadline = microtime(true) + 5;
    while (! file_exists($socket) && microtime(true) < $deadline) {
        usleep(10000);
    }
    $connection = stream_socket_client('unix://'.$socket, $errno, $error, 5);
    if ($connection === false) {
        throw new RuntimeException('Cannot connect to isolated FPM: '.$error);
    }
    stream_set_timeout($connection, 3);
    $params = '';
    foreach (['SCRIPT_FILENAME' => __DIR__.'/Fixtures/fpm-deferred.php', 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'BOTECT_TEST_MARKER' => $marker] as $name => $value) {
        $params .= fcgiLength(strlen($name)).fcgiLength(strlen($value)).$name.$value;
    }
    fwrite($connection, fcgiRecord(1, pack('nCxxxxx', 1, 0)).fcgiRecord(4, $params).fcgiRecord(4, '').fcgiRecord(5, ''));
    $stdout = '';
    while (true) {
        $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', fcgiRead($connection, 8));
        $body = fcgiRead($connection, $header['length']);
        fcgiRead($connection, $header['padding']);
        if ($header['type'] === 6) {
            $stdout .= $body;
        }
        if ($header['type'] === 3) {
            break;
        }
    }
    fclose($connection);
    if (! str_contains($stdout, 'RESPONSE|APP-SHUTDOWN') || is_file($marker)) {
        throw new RuntimeException('Response did not complete before slow delivery: '.$stdout);
    }
    $deadline = microtime(true) + 2;
    while (! is_file($marker.'.started') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($marker.'.started')) {
        throw new RuntimeException('Deferred transport did not start.');
    }
    file_put_contents($marker.'.release', 'release');
    $deadline = microtime(true) + 2;
    while (! is_file($marker) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($marker) || file_get_contents($marker) !== 'delivered') {
        throw new RuntimeException('Deferred delivery failed: '.(is_file($marker) ? file_get_contents($marker) : 'no completion marker').'; '.file_get_contents($directory.'/error.log'));
    }
    echo "FPM passed: full response completed before delivery; native session lock released.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}
