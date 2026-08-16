<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

/*
 * What a scanned QR is allowed to change about this device's relay.
 *
 * The QR is an out-of-band channel — the same code hands over the initiator's
 * identity keys — so it is trusted enough to point a phone at a LAN relay.
 * It is not trusted enough to redirect somebody's self-hosted one.
 *
 * The bug this pins: bailing out whenever ANY endpoint was already stored.
 * A phone that scanned a first, broken QR held the endpoint forever after,
 * and no later scan could hand it the token and pin that endpoint needs — so
 * every retry of the ceremony failed in the same place, for a reason nothing
 * on screen could explain.
 */
beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-qr-relay-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

it('configures a fresh device from the QR', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'token-from-qr', 'sha256//pinned');

    expect($config->endpointUrl())->toBe('https://192.168.1.20:51338')
        ->and($config->authToken())->toBe('token-from-qr')
        ->and($config->pin())->toBe('sha256//pinned');
});

it('completes the credentials of an endpoint it already holds', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    // The state left by a first attempt against a relay that had no token
    // yet: the endpoint stuck, the credentials never arrived.
    $config->setEndpointUrl('https://192.168.1.20:51338');

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'token-from-qr', 'sha256//pinned');

    expect($config->authToken())->toBe('token-from-qr')
        ->and($config->pin())->toBe('sha256//pinned');
});

it('follows a desktop that moved to a new LAN address', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $config->setEndpointUrl('https://192.168.1.20:51338');
    $config->setAuthToken('stale-token');

    $gateway->configureRelayFromQr('https://10.0.0.9:51338', 'token-from-qr', 'sha256//pinned');

    expect($config->endpointUrl())->toBe('https://10.0.0.9:51338')
        ->and($config->authToken())->toBe('token-from-qr');
});

it('refuses to redirect a self-hosted relay', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $config->setEndpointUrl('https://relay.example.com');
    $config->setAuthToken('operator-secret');

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'token-from-qr', 'sha256//pinned');

    expect($config->endpointUrl())->toBe('https://relay.example.com')
        ->and($config->authToken())->toBe('operator-secret');
});

it('refuses an endpoint the transport would later reject', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    // Plain http to a public host. Storing it would leave the device holding
    // a relay RelayClient refuses on every send.
    $gateway->configureRelayFromQr('http://relay.example.com', 'token-from-qr', null);

    expect($config->endpointUrl())->toBeNull();
});
