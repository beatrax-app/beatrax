<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// A fresh phone has no relay configured, so the cross-device pre-confirm
// handshake has no transport at all until configureRelayFromQr() runs. It
// persists transport config only; no new trust decision is taken here.

function relayBootstrapUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

afterEach(function (): void {
    // Never leave a relay config artifact in storage/app for a subsequent,
    // unrelated test to trip over.
    $tokenPath = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.'sync-relay-token.json';
    $relayPath = UserDataPathService::appPath('sync/relay.json');

    foreach ([$tokenPath, $relayPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('configures the relay endpoint + auth token from QR-carried values', function (): void {
    $user = relayBootstrapUser('relay-bootstrap-configure');
    test()->actingAs($user);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->configureRelayFromQr('https://relay.example.com', 'shared-secret');

    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);

    expect($config->endpointUrl())->toBe('https://relay.example.com');
    expect($config->authToken())->toBe('shared-secret');
});

it('is a no-op when the QR carried no relay endpoint — never dead-ends a relay-less device', function (): void {
    $user = relayBootstrapUser('relay-bootstrap-noop');
    test()->actingAs($user);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->configureRelayFromQr(null, null);

    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);

    expect($config->isConfigured())->toBeFalse();
});

it('configures the endpoint without a token when the relay is deployed token-less', function (): void {
    $user = relayBootstrapUser('relay-bootstrap-no-token');
    test()->actingAs($user);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->configureRelayFromQr('https://relay.example.com', null);

    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);

    expect($config->endpointUrl())->toBe('https://relay.example.com');
    expect($config->authToken())->toBeNull();
});

it('MED-01: rejects a non-https endpoint from scanned QR input — never durably breaks relay transport', function (): void {
    $user = relayBootstrapUser('relay-bootstrap-insecure');
    test()->actingAs($user);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->configureRelayFromQr('http://attacker.example.com', 'x');

    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);
    expect($config->isConfigured())->toBeFalse('an http:// endpoint from a scanned QR must never be persisted');
});

it('MED-01: does not clobber an already-configured relay — fresh-device bootstrap only', function (): void {
    $user = relayBootstrapUser('relay-bootstrap-noclobber');
    test()->actingAs($user);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);

    $config->setEndpointUrl('https://existing-relay.example.com');
    $config->setAuthToken('existing-secret');

    $gateway->configureRelayFromQr('https://attacker-relay.example.com', 'attacker-secret');

    expect($config->endpointUrl())->toBe('https://existing-relay.example.com');
    expect($config->authToken())->toBe('existing-secret');
});
