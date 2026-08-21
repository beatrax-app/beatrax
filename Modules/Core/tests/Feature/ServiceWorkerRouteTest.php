<?php

declare(strict_types=1);

it('/sw.js is publicly accessible without authentication', function (): void {
    $response = $this->get('/sw.js');
    $response->assertOk();
});

it('/sw.js returns Content-Type application/javascript', function (): void {
    $response = $this->get('/sw.js');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/javascript');
});

it('/sw.js contains the Beatrax cache name prefix', function (): void {
    $html = (string) $this->get('/sw.js')->getContent();
    expect($html)->toContain('beatrax-shell-v');
});

it('/sw.js contains the version from config(nativephp.version)', function (): void {
    $version = config('nativephp.version');
    $html = (string) $this->get('/sw.js')->getContent();
    expect($html)->toContain($version);
});
