<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\PinHasher;
use Modules\Auth\Internal\Lock\PwhashLimits;

// phpunit.xml lowers the tier for the suite, so the running config answers with
// the reduced one and cannot witness what ships. env() reads $_ENV, $_SERVER
// and getenv(), so all three have to be taken away before the shipped file is
// evaluated for what an install with no override resolves.
function appLockKdfTierWithNoEnvOverride(): mixed
{
    $override = getenv('APP_LOCK_KDF_TIER');

    putenv('APP_LOCK_KDF_TIER');
    unset($_ENV['APP_LOCK_KDF_TIER'], $_SERVER['APP_LOCK_KDF_TIER']);

    try {
        return (require base_path('config/auth.php'))['app_lock']['kdf_tier'];
    } finally {
        if ($override !== false) {
            putenv('APP_LOCK_KDF_TIER='.$override);
            $_ENV['APP_LOCK_KDF_TIER'] = $override;
            $_SERVER['APP_LOCK_KDF_TIER'] = $override;
        }
    }
}

it('ships the moderate limits to an install that overrides nothing', function (): void {
    $shipped = PwhashLimits::fromTier(appLockKdfTierWithNoEnvOverride());

    expect($shipped->opslimit)->toBe(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE)
        ->and($shipped->memlimit)->toBe(SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE);
});

it('reads back the override the suite itself is running under', function (): void {
    $active = app(PwhashLimits::class);

    expect($active->opslimit)->toBe(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE)
        ->and($active->memlimit)->toBe(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
});

it('hashes at the limits it was handed rather than at a constant of its own', function (): void {
    $hash = (new PinHasher(PwhashLimits::fromTier(PwhashLimits::REDUCED_TIER)))->hash('123456');

    expect($hash)
        ->toContain('m='.intdiv(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, 1024))
        ->toContain('t='.SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE);
});

it('derives at the limits it was handed rather than at a constant of its own', function (): void {
    $limits = PwhashLimits::fromTier(PwhashLimits::REDUCED_TIER);
    $kdf = new AppLockKdf($limits);
    $salt = $kdf->generateSalt();

    $derived = $kdf->deriveWrapKey('123456', $salt);
    $expected = sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        '123456',
        $salt,
        $limits->opslimit,
        $limits->memlimit,
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
    );

    expect($derived)->toBe($expected);
    sodium_memzero($derived);
    sodium_memzero($expected);
});

it('verifies a stored hash whose cost is not the one it is configured for', function (): void {
    $stored = (new PinHasher(PwhashLimits::fromTier(PwhashLimits::REDUCED_TIER)))->hash('123456');
    $production = new PinHasher(PwhashLimits::fromTier(PwhashLimits::PRODUCTION_TIER));

    expect($production->verify('123456', $stored))->toBeTrue();
});
