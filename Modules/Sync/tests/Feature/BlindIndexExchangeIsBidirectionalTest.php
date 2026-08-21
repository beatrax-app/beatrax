<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkEpochWrapSignature;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
function bidUser(): User
{
    return User::query()->create([
        'username' => 'bid-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function bidConfirmedPeer(int $userId): array
{
    $sigKp = sodium_crypto_sign_keypair();
    $boxKp = sodium_crypto_box_keypair();
    $deviceId = 'bid-peer-'.bin2hex(random_bytes(4));

    $registryId = (int) app(DatabaseManager::class)->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'desktop',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    return [$registryId, $deviceId];
}

function bidProductionSources(string $directory): array
{
    $sources = [];
    $root = base_path($directory);

    if (! is_dir($root)) {
        return $sources;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $sources[$path] = (string) file_get_contents($path);
    }

    return $sources;
}

// The premise the tie-break rests on. `adoptsBlindIndexKey()` reasons about a
// peer running the same branch inverted, and in the topology this product
// ships — one desktop settings modal, one phone that only ever received —
// the peer was not running it at all.
it('gives the phone a production fan-out, so the key exchange is not one-directional', function (): void {
    $callers = [];

    foreach (['Modules'] as $directory) {
        foreach (bidProductionSources($directory) as $path => $source) {
            if (str_contains($source, 'deliverAllEpochsToDevice(') || str_contains($source, 'fanOutAllEpochsToDevice(')) {
                $callers[] = $path;
            }
        }
    }

    $mobileCallers = array_values(array_filter(
        $callers,
        static fn (string $path): bool => str_contains($path, '/Modules/Mobile/'),
    ));

    expect($mobileCallers)->not->toBeEmpty(
        'the phone must fan its own keys out, or the desktop settles the tie over a flag it was never sent',
    );
});

// A fan-out with no send path queues wraps into a mailbox nothing reads.
it('gives the phone a send path for the wraps its fan-out queued', function (): void {
    $client = (string) file_get_contents(base_path('Modules/Mobile/Internal/Sync/LanSyncClient.php'));

    expect($client)->toContain('pendingWrapsFor(')
        ->and($client)->toContain('confirmDelivered(');
});

it('offers the phone-side fan-out to the push leg as a pending wrap for that peer', function (): void {
    $user = bidUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, $session);

    [$registryId, $peerDeviceId] = bidConfirmedPeer((int) $user->id);

    app(PairingGateway::class)->deliverAllEpochsToDevice((int) $user->id, $registryId, $session);

    /** @var GdkEpochDeliveryGateway $gateway */
    $gateway = app(GdkEpochDeliveryGateway::class);
    $pending = $gateway->pendingWrapsFor($peerDeviceId);

    $roles = array_map(
        static fn (array $wrap): string => GdkEpochWrapSignature::roleOf((array) json_decode($wrap['blob'], true)),
        $pending,
    );

    expect($roles)->toContain(GdkEpochWrapSignature::ROLE_BLIND_INDEX)
        ->and($roles)->toContain(GdkEpochWrapSignature::ROLE_EPOCH);
});

// Confirming on the way out marked a wrap delivered that a dropped connection
// meant the peer never saw, and nothing re-sends a fan-out.
it('leaves a pushed wrap pending until the peer has acknowledged that exact row', function (): void {
    $user = bidUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, $session);

    [$registryId, $peerDeviceId] = bidConfirmedPeer((int) $user->id);
    app(PairingGateway::class)->deliverAllEpochsToDevice((int) $user->id, $registryId, $session);

    /** @var GdkEpochDeliveryGateway $gateway */
    $gateway = app(GdkEpochDeliveryGateway::class);
    $pending = $gateway->pendingWrapsFor($peerDeviceId);

    expect($pending)->toHaveCount(2);

    // One acknowledged of two sent: the second must still be offered.
    $gateway->confirmDelivered($pending[0]['id']);

    expect($gateway->pendingWrapsFor($peerDeviceId))->toHaveCount(1);
});
