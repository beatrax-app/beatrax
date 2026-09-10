<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

uses(RefreshDatabase::class);

// RelayDrainRegistry is trust-on-first-use, so the first token a relay sees for
// a device id is the one it accepts from then on. One token per device meant
// the same bearer was presented at every relay the device was ever pointed at
// -- and a scanned QR can repoint it. A hostile relay learned a credential it
// could then spend at the legitimate one, against a mailbox holding GDK wraps.

function drainTokensPath(): string
{
    return UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.'sync-relay-drain-tokens.json';
}

/**
 * @return array<string, string>
 */
function storedDrainTokens(): array
{
    /** @var array{tokens?: array<string, string>} $data */
    $data = json_decode((string) file_get_contents(drainTokensPath()), true, 512, JSON_THROW_ON_ERROR);

    return $data['tokens'] ?? [];
}

it('mints a different token for the same device at a second relay', function (): void {
    $config = new RelayConfig;

    $config->setEndpointUrl('https://relay.household.test');
    $atHome = $config->deviceDrainToken('device-a');

    $config->setEndpointUrl('https://relay.attacker.test');
    $atStranger = $config->deviceDrainToken('device-a');

    expect($atStranger)->not->toBe(
        $atHome,
        'The token the household relay pinned was handed to a second relay, '.
        'which can spend it at the first.',
    );
});

// The positive control. Without it a token that were freshly minted on every
// call would satisfy the case above and break every drain in the product.
it('keeps one token for one device while the relay is unchanged', function (): void {
    $config = new RelayConfig;
    $config->setEndpointUrl('https://relay.household.test');

    expect($config->deviceDrainToken('device-a'))->toBe($config->deviceDrainToken('device-a'));
});

it('comes back to the first token when the device is pointed home again', function (): void {
    $config = new RelayConfig;

    $config->setEndpointUrl('https://relay.household.test');
    $atHome = $config->deviceDrainToken('device-a');

    $config->setEndpointUrl('https://relay.attacker.test');
    $config->deviceDrainToken('device-a');

    $config->setEndpointUrl('https://relay.household.test');

    expect($config->deviceDrainToken('device-a'))->toBe(
        $atHome,
        'A device that returns to its own relay must present what that relay '.
        'already recorded, or its own mailbox answers 401 forever.',
    );
});

// A URL is not a canonical string, and two spellings of one relay would mint
// two tokens -- the second of which that relay refuses.
it('reads a trailing slash as the same relay', function (): void {
    $config = new RelayConfig;

    $config->setEndpointUrl('https://relay.household.test');
    $plain = $config->deviceDrainToken('device-a');

    $config->setEndpointUrl('https://relay.household.test/');

    expect($config->deviceDrainToken('device-a'))->toBe($plain);
});

it('keeps two device ids apart at one relay', function (): void {
    $config = new RelayConfig;
    $config->setEndpointUrl('https://relay.household.test');

    expect($config->deviceDrainToken('device-a'))->not->toBe($config->deviceDrainToken('device-b'));
});

// An install upgrading holds a bare device id, and the relay it is pointed at
// has already recorded that token. Minting a second one there would answer 401
// against its own mailbox for the life of the install.
it('carries a token written before the scoping forward to the relay that pinned it', function (): void {
    $secrets = UserDataPathService::secretsPath();
    if (! is_dir($secrets)) {
        mkdir($secrets, 0700, true);
    }

    file_put_contents(
        drainTokensPath(),
        json_encode(['tokens' => ['device-a' => 'bdt1.legacy.token']], JSON_THROW_ON_ERROR),
    );

    $config = new RelayConfig;
    $config->setEndpointUrl('https://relay.household.test');

    expect($config->deviceDrainToken('device-a'))->toBe('bdt1.legacy.token');

    // And the unscoped entry is gone, so a later relay cannot inherit it.
    expect(array_keys(storedDrainTokens()))->not->toContain('device-a');

    $config->setEndpointUrl('https://relay.attacker.test');

    expect($config->deviceDrainToken('device-a'))->not->toBe('bdt1.legacy.token');
});
