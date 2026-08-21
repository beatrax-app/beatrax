<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\EmailScan\Public\LoopbackRedirectUri;

it('uses the explicit OAUTH_LOOPBACK_PORT override when set', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => 9123],
        'app' => ['url' => 'https://beatrax.test'],
    ]);

    $loopback = new LoopbackRedirectUri($config);

    expect($loopback->forProvider('gmail'))->toBe('http://127.0.0.1:9123/oauth/callback/gmail');
});

it('accepts a numeric string OAUTH_LOOPBACK_PORT override (env-vars are strings)', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => '9123'],
        'app' => ['url' => 'https://beatrax.test'],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('microsoft'))
        ->toBe('http://127.0.0.1:9123/oauth/callback/microsoft');
});

it('falls back to app.url port when host is 127.0.0.1 and port is present', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => null],
        'app' => ['url' => 'http://127.0.0.1:8000'],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('gmail'))
        ->toBe('http://127.0.0.1:8000/oauth/callback/gmail');
});

it('falls back to app.url port when host is localhost and port is present', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => null],
        'app' => ['url' => 'http://localhost:9999'],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('microsoft'))
        ->toBe('http://127.0.0.1:9999/oauth/callback/microsoft');
});

it('ignores app.url host/scheme and uses port 8000 when app.url is a .test domain', function (): void {
    // Both providers reject a .test redirect, so the callback has to land on
    // the loopback IP; 8000 unless OAUTH_LOOPBACK_PORT says otherwise.
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => null],
        'app' => ['url' => 'https://beatrax.test'],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('gmail'))
        ->toBe('http://127.0.0.1:8000/oauth/callback/gmail');
});

it('defaults to port 8000 when app.url is unset entirely', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => null],
        'app' => [],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('gmail'))
        ->toBe('http://127.0.0.1:8000/oauth/callback/gmail');
});

it('defaults to the http scheme when no scheme argument is passed (gmail/microsoft are unaffected by the Public promotion)', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => 9123],
        'app' => ['url' => 'https://beatrax.test'],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('microsoft'))
        ->toBe('http://127.0.0.1:9123/oauth/callback/microsoft');
});

it('honors an explicit https scheme override (19-05 Gate 0/A2: Enable Banking requires HTTPS-only loopback redirects)', function (): void {
    $config = new Repository([
        'email-scan' => ['oauth_loopback_port' => 9123],
        'app' => ['url' => 'https://beatrax.test'],
    ]);

    expect((new LoopbackRedirectUri($config))->forProvider('open-banking', scheme: 'https'))
        ->toBe('https://127.0.0.1:9123/oauth/callback/open-banking');
});
