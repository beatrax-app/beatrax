<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// The QR is an out-of-band channel that already hands over the initiator's
// identity keys, so it may point a phone at a LAN relay but never redirect a
// self-hosted one. Bailing out whenever any endpoint was already stored left a
// phone that scanned a first, broken QR unable to ever receive its pin.
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

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'sha256//pinned');

    expect($config->endpointUrl())->toBe('https://192.168.1.20:51338')
        ->and($config->pin())->toBe('sha256//pinned');
});

it('completes the pin of an endpoint it already holds', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    // The state left by a first attempt against a relay that had no TLS
    // material yet: the endpoint stuck, the pin never arrived.
    $config->setEndpointUrl('https://192.168.1.20:51338');

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'sha256//pinned');

    expect($config->pin())->toBe('sha256//pinned');
});

it('follows a desktop that moved to a new LAN address', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $config->setEndpointUrl('https://192.168.1.20:51338');
    $config->setPin('sha256//stale');

    $gateway->configureRelayFromQr('https://10.0.0.9:51338', 'sha256//pinned');

    expect($config->endpointUrl())->toBe('https://10.0.0.9:51338')
        ->and($config->pin())->toBe('sha256//pinned');
});

it('refuses to redirect a self-hosted relay', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $config->setEndpointUrl('https://relay.example.com');
    $config->setPin('sha256//operator');

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'sha256//pinned');

    expect($config->endpointUrl())->toBe('https://relay.example.com')
        ->and($config->pin())->toBe('sha256//operator');
});

it('refuses an endpoint the transport would later reject', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    // Plain http to a public host. Storing it would leave the device holding
    // a relay RelayClient refuses on every send.
    $gateway->configureRelayFromQr('http://relay.example.com', null);

    expect($config->endpointUrl())->toBeNull();
});

it('carries no relay credential from the QR at all', function (): void {
    /** @var PairingGateway $gateway */
    $gateway = $this->app->make(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $gateway->configureRelayFromQr('https://192.168.1.20:51338', 'sha256//pinned');

    // The relay-wide bearer this used to plant is the one a past peer drained
    // an unclaimed mailbox with. Nothing writes it now, so a scanned QR leaves
    // no such secret behind.
    $tokenFile = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.'sync-relay-token.json';

    expect(is_file($tokenFile))->toBeFalse()
        ->and($config->endpointUrl())->toBe('https://192.168.1.20:51338');
});
