<?php

declare(strict_types=1);

use Modules\Core\Internal\Encryption\ProductionKdfCost;
use Modules\Core\Public\Contracts\KdfCost;
use Modules\Core\Public\Services\BackupEncryptor;

// The suite derives at Tests\Helpers\CheapKdfCost, which is ~17,000x faster
// than what ships. That trade is only safe while the shipped cost is asserted
// somewhere the substitution cannot reach, which is what this file is: every
// case below builds ProductionKdfCost itself, or reads it out of an
// application booted the way bootstrap/app.php boots one.

it('derives the pinned key from the pinned passphrase and salt', function (): void {
    $cost = new ProductionKdfCost;

    $key = sodium_crypto_pwhash(
        32,
        'beatrax-kdf-cost-vector',
        hex2bin('000102030405060708090a0b0c0d0e0f'),
        $cost->opslimit(),
        $cost->memlimit(),
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
    );

    // A known-answer vector rather than three separate equality assertions:
    // it fails on a weakened opslimit, a weakened memlimit, a swapped
    // algorithm and a shortened output, and cannot be updated by accident.
    expect(bin2hex($key))
        ->toBe('c9012e1e8a9d6b4600040e37be9b540bc378b674138028f40b9303d6ec4042db');
});

it('names libsodium MODERATE, which is three passes over 256 MiB', function (): void {
    $cost = new ProductionKdfCost;

    expect($cost->opslimit())->toBe(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE)
        ->and($cost->memlimit())->toBe(SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE)
        ->and($cost->opslimit())->toBe(3)
        ->and($cost->memlimit())->toBe(268435456);
});

it('binds that cost in an application booted without the test harness', function (): void {
    // createApplication() re-reads bootstrap/app.php and bootstraps the kernel,
    // so this is the wiring a shipped install gets. Tests\TestCase replaced the
    // binding on $this->app only; a conditional in CoreServiceProvider keyed on
    // the environment would be visible here, because APP_ENV is still testing.
    $shipped = $this->createApplication();

    expect($shipped->make(KdfCost::class))->toBeInstanceOf(ProductionKdfCost::class);
});

it('writes those parameters into a backup header', function (): void {
    $directory = sys_get_temp_dir().'/beatrax-kdf-pin-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/plain', 'a backup');

    try {
        (new BackupEncryptor(new ProductionKdfCost))
            ->encrypt($directory.'/plain', $directory.'/enc', 'a-good-passphrase');

        expect((new BackupEncryptor(new ProductionKdfCost))->kdfParams($directory.'/enc'))
            ->toBe([SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE]);
    } finally {
        @unlink($directory.'/plain');
        @unlink($directory.'/enc');
        @rmdir($directory);
    }
});

it('ships exactly one implementation of the cost contract', function (): void {
    // A second one under Modules/ or app/ would be a place a future edit could
    // bind a cheaper cost into a real install. The cheap one deliberately lives
    // under tests/, which is an autoload-dev root a shipped bundle omits.
    $repoRoot = dirname((string) realpath(base_path('Modules')));
    $implementations = [];

    foreach (['Modules', 'app'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            if (preg_match('/implements\s+[^{]*\bKdfCost\b/', (string) file_get_contents($path)) === 1) {
                $implementations[] = str_replace($repoRoot.'/', '', $path);
            }
        }
    }

    sort($implementations);

    expect($implementations)->toBe(['Modules/Core/Internal/Encryption/ProductionKdfCost.php']);
});

it('lets no second class name a derivation cost of its own', function (): void {
    // The test above asks who implements the contract, which a second cost
    // only answers to if it was written as one. A rival abstraction under any
    // other name -- a value object, a static factory, a constant on the class
    // that derives -- has to name these constants to set a cost, so this asks
    // the question the other one cannot: who spends Argon2id work here at all.
    $repoRoot = dirname((string) realpath(base_path('Modules')));
    $namers = [];

    foreach (['Modules', 'app'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            if (preg_match('/SODIUM_CRYPTO_PWHASH_(?:OPS|MEM)LIMIT_/', (string) file_get_contents($path)) === 1) {
                $namers[] = str_replace($repoRoot.'/', '', $path);
            }
        }
    }

    sort($namers);

    // BackupEncryptor is the second entry on purpose: its constants are the
    // ceiling it refuses a backup header above, not a cost it derives at, and
    // that bound must hold still if the shipped cost ever rises.
    expect($namers)->toBe([
        'Modules/Core/Internal/Encryption/ProductionKdfCost.php',
        'Modules/Core/Public/Services/BackupEncryptor.php',
    ]);
});
