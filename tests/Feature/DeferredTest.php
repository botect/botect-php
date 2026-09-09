<?php

declare(strict_types=1);

use Botect\Botect;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\HttpTransport;
use Botect\Delivery\DeferredDispatcher;
use Botect\Http\Response;
use Botect\Storage\FileSpool;
use Botect\Tests\FakeTransport;

test('Laravel default needs no queue worker and sends only at termination', function (): void {
    $transport = new FakeTransport;
    $transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-09T00:00:00Z"}');
    $this->app->instance(HttpTransport::class, $transport);
    expect(config('botect.delivery'))->toBe('deferred')
        ->and($this->app->make(Dispatcher::class))->toBeInstanceOf(DeferredDispatcher::class);
    $sdk = $this->app->make(Botect::class);
    expect($sdk->loggedIn('sess_first'))->toBeTrue()->and($transport->requests)->toBe([]);
    $this->app->terminate();
    expect($transport->requests)->toHaveCount(1);
    $this->app->terminate();
    expect($transport->requests)->toHaveCount(1);
    $this->app->forgetScopedInstances();
    $next = $this->app->make(Botect::class);
    expect($next)->not->toBe($sdk);
    $next->loggedIn('sess_second');
    expect($transport->requests)->toHaveCount(1);
    $this->app->terminate();
    expect($transport->requests)->toHaveCount(2);
});

test('Laravel termination discards failed sends instead of retrying or failing the app', function (): void {
    $transport = new FakeTransport;
    $transport->result = new Response(503, 'unavailable');
    $this->app->instance(HttpTransport::class, $transport);
    $this->app->make(Botect::class)->loggedIn('sess_test');
    $this->app->terminate();
    $this->app->terminate();
    expect($transport->requests)->toHaveCount(1);
});

test('file-spool delivery remains an explicit Laravel option', function (): void {
    config(['botect.delivery' => 'spool']);
    expect($this->app->make(Dispatcher::class))->toBeInstanceOf(FileSpool::class);
});

test('termination resolves the dispatcher from a cloned application sandbox', function (): void {
    $sandbox = clone $this->app;
    $sandbox->instance('app', $sandbox);
    $transport = new FakeTransport;
    $transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-09T00:00:00Z"}');
    $sandbox->instance(HttpTransport::class, $transport);
    $sandbox->make(Botect::class)->loggedIn('sess_sandbox');
    expect($this->app->resolved(Dispatcher::class))->toBeFalse()->and($transport->requests)->toBe([]);
    $sandbox->terminate();
    expect($transport->requests)->toHaveCount(1);
});
