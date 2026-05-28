<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemClock;
use Psr\Log\NullLogger;

/*
 * ElectronUpdateChannel::verifyManifest is the SOLE binary-integrity
 * signal for the auto-update path. With no OS-level code-signing on
 * any platform, a tampered latest.yml that flips even a single byte of
 * the manifest body or the detached signature MUST cause verification
 * to fail and prevent the SystemAlertsBanner from advertising a
 * compromised release.
 *
 * Tests construct the service directly with a stub Repository so the
 * production config file stays pristine while each test fixes a
 * throwaway Ed25519 keypair via sodium_crypto_sign_keypair().
 */

/**
 * Build the channel against a stub config Repository carrying the
 * provided public-key hex under auto_update.publisher_public_key_hex.
 */
function makeChannelWithPublicKeyHex(string $publicKeyHex): ElectronUpdateChannel
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $config = new Repository([
        'auto_update' => [
            'publisher_public_key_hex' => $publicKeyHex,
            'update_channel' => 'stable',
        ],
    ]);

    return new ElectronUpdateChannel(
        $db,
        new NullLogger(),
        new SystemClock(),
        $config,
    );
}

/**
 * Generate a throwaway Ed25519 keypair via libsodium. Returns
 * ['secret' => 64-byte binary, 'public' => 32-byte binary,
 *  'public_hex' => 64 hex chars].
 *
 * @return array{secret: string, public: string, public_hex: string}
 */
function generateEd25519Fixture(): array
{
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);

    return [
        'secret' => $secret,
        'public' => $public,
        'public_hex' => bin2hex($public),
    ];
}

it('returns true when verifying a manifest body against its own detached signature', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: abc123\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);

    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($manifestBody, $signature))->toBeTrue();
});

it('returns false when the manifest body has been tampered (single byte flipped) with the original signature', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: abc123\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);

    // Flip a single byte deep in the body — sha512 line, last hex digit.
    $tamperedBody = substr_replace($manifestBody, '4', strpos($manifestBody, 'abc123') + 5, 1);
    expect($tamperedBody)->not->toBe($manifestBody);

    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($tamperedBody, $signature))->toBeFalse();
});

it('returns false when the signature has been tampered (single byte flipped) with the original manifest', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: abc123\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);

    // Flip the first byte of the 64-byte signature.
    $tamperedSig = ($signature[0] === 'a' ? 'b' : 'a').substr($signature, 1);
    expect($tamperedSig)->not->toBe($signature);

    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($manifestBody, $tamperedSig))->toBeFalse();
});

it('returns false on malformed signature length without throwing to callers', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\n";

    // 17 bytes — clearly not the 64-byte Ed25519 signature size, so
    // libsodium raises SodiumException internally. The contract says
    // verifyManifest swallows it and returns false; this guards
    // against a malformed wire packet crashing the poll loop.
    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($manifestBody, 'not-a-valid-signature'))->toBeFalse();
});
