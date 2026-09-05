<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\LocalRelayProvisioner;

uses(RefreshDatabase::class);

// Endpoint and certificate pin travel together in the QR, and a device missing
// either looks from the phone exactly like pairing that never completes. The
// provisioner established both on its first run only, so a device that stored
// an endpoint early short-circuited into a relay it could never verify.
beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-provisioner-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

// The provisioner reads the routing table for a LAN address; a CI container
// without a default route has nothing to advertise and nothing to assert on.
function provisionedEndpointOrSkip(LocalRelayProvisioner $provisioner, int $port = 51338): string
{
    $endpoint = $provisioner->ensureConfigured($port);

    if ($endpoint === null) {
        test()->markTestSkipped('no routable LAN address in this environment');
    }

    return $endpoint;
}

it('establishes the endpoint and the pin together', function (): void {
    /** @var LocalRelayProvisioner $provisioner */
    $provisioner = $this->app->make(LocalRelayProvisioner::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $endpoint = provisionedEndpointOrSkip($provisioner);

    expect($endpoint)->toStartWith('https://')
        ->and($config->endpointUrl())->toBe($endpoint)
        ->and($config->pin())->toStartWith('sha256//');
});

it('fills in a pin a device stored an endpoint without', function (): void {
    /** @var LocalRelayProvisioner $provisioner */
    $provisioner = $this->app->make(LocalRelayProvisioner::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    // The state a device reached by storing its endpoint before TLS material
    // existed. Every connection from here is unverifiable.
    $endpoint = provisionedEndpointOrSkip($provisioner);
    $config->setPin(null);

    expect($config->pin())->toBeNull();

    $provisioner->ensureConfigured(51338);

    expect($config->endpointUrl())->toBe($endpoint)
        ->and($config->pin())->toStartWith('sha256//');
});

it('re-points a LAN endpoint whose address no longer belongs to this machine', function (): void {
    /** @var LocalRelayProvisioner $provisioner */
    $provisioner = $this->app->make(LocalRelayProvisioner::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    $current = provisionedEndpointOrSkip($provisioner);

    // A laptop that moved between networks: the stored address is a private
    // one, but it is now somebody else's machine, and every peer holding it
    // from an old QR dials a host that will not answer.
    $config->setEndpointUrl('https://10.55.55.55:51338');

    expect($provisioner->ensureConfigured(51338))->toBe($current)
        ->and($config->endpointUrl())->toBe($current);
});

it('never re-points or re-pins an operator-hosted relay', function (): void {
    /** @var LocalRelayProvisioner $provisioner */
    $provisioner = $this->app->make(LocalRelayProvisioner::class);
    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    // Somebody's own relay on a public host. It is configuration this device
    // does not own: not its address to change, not its key to pin.
    $config->setEndpointUrl('https://relay.example.com');

    expect($provisioner->ensureConfigured(51338))->toBe('https://relay.example.com')
        ->and($config->endpointUrl())->toBe('https://relay.example.com')
        ->and($config->pin())->toBeNull();
});
