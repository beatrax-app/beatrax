<?php

declare(strict_types=1);

/**
 * Feature tests proving the global LoopbackOnly middleware refuses any
 * request whose `SERVER_ADDR` is not `127.0.0.1` or `::1`, while letting
 * loopback (and CLI/test fixtures with no SERVER_ADDR) through.
 */
it('refuses a non-loopback SERVER_ADDR with 404', function (): void {
    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
        ->get('/login')
        ->assertNotFound();
});

it('allows 127.0.0.1 (IPv4 loopback)', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('allows ::1 (IPv6 loopback)', function (): void {
    $response = $this->withServerVariables(['SERVER_ADDR' => '::1'])->get('/login');
    expect($response->status())->not->toBe(404);
});

it('allows requests without SERVER_ADDR (CLI / test fixtures)', function (): void {
    $response = $this->get('/login');
    expect($response->status())->not->toBe(404);
});
