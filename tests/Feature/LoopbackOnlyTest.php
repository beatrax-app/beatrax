<?php

declare(strict_types=1);

it('refuses a non-loopback SERVER_ADDR with 404', function (): void {
    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('/login')
        ->assertNotFound();
});

it('allows 127.0.0.1 (IPv4 loopback)', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('allows other addresses inside the 127.0.0.0/8 range', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '127.0.0.2'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('allows ::1 (IPv6 loopback)', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '::1'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('allows ::ffff:127.0.0.1 (IPv4-mapped IPv6 loopback)', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '::ffff:127.0.0.1'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('allows the compressed ::ffff:7f00:1 form of v4-mapped loopback', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '::ffff:7f00:1'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('refuses ::ffff:192.168.1.10 (v4-mapped non-loopback)', function (): void {
    $this->withServerVariables(['SERVER_ADDR' => '::ffff:192.168.1.10'])
        ->get('/login')
        ->assertNotFound();
});

it('refuses malformed SERVER_ADDR strings', function (): void {
    $this->withServerVariables(['SERVER_ADDR' => 'not-an-ip'])
        ->get('/login')
        ->assertNotFound();
});

it('allows requests without SERVER_ADDR (CLI / test fixtures)', function (): void {
    $response = $this->get('/login');
    expect($response->status())->not->toBe(404);
});
