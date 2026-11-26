<?php

declare(strict_types=1);

use Botect\Support\HeaderShape;

$fixtures = json_decode(<<<'JSON'
[
  {"case":"ordered","header_names":["host","accept","user-agent"],"normalized":["host","accept","user-agent"],"shape":"c0d7f5f1a627cd7a2a285ebaf4a1ad3c"},
  {"case":"reordered","header_names":["accept","host","user-agent"],"normalized":["accept","host","user-agent"],"shape":"1c8f3b0e29d15c7dd975e89985291ead"},
  {"case":"duplicate","header_names":["host","accept","host","user-agent"],"normalized":["host","accept","user-agent"],"shape":"c0d7f5f1a627cd7a2a285ebaf4a1ad3c"},
  {"case":"mixed-case","header_names":["Host","ACCEPT","User-Agent"],"normalized":["host","accept","user-agent"],"shape":"c0d7f5f1a627cd7a2a285ebaf4a1ad3c"},
  {"case":"excluded","header_names":["Host","X-Forwarded-For","Accept","X-Custom","User-Agent"],"normalized":["host","accept","user-agent"],"shape":"c0d7f5f1a627cd7a2a285ebaf4a1ad3c"},
  {"case":"empty","header_names":[],"normalized":[],"shape":"e3b0c44298fc1c149afbf4c8996fb924"}
]
JSON, true, 32, JSON_THROW_ON_ERROR);

foreach ($fixtures as $fixture) {
    test('shared shape fixture '.$fixture['case'], function () use ($fixture): void {
        expect(HeaderShape::normalize($fixture['header_names']))->toBe($fixture['normalized'])
            ->and(HeaderShape::hash($fixture['header_names']))->toBe($fixture['shape']);
    });
}
