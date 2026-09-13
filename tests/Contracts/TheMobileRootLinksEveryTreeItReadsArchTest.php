<?php

declare(strict_types=1);

/**
 * @link ../../.docs/features/mobile/architecture.md#the-mobile-root-reaches-the-application-by-symlink-and-one-link-was-missing
 */

/**
 * Every symlink git tracks under mobile-app/, as the index records it.
 *
 * Read from the index rather than the filesystem: a checkout on a system
 * without symlink support materialises them as text files, and the question
 * here is what the repository says, not what one machine did with it.
 *
 * @return array<string, string> repo-relative path => link target
 */
function mobileRootTrackedLinks(): array
{
    $listing = shell_exec('cd '.escapeshellarg(base_path()).' && git ls-files -s mobile-app');

    $links = [];
    foreach (explode("\n", (string) $listing) as $line) {
        if (! str_starts_with($line, '120000 ')) {
            continue;
        }

        $path = trim((string) strstr($line, "\t"));
        $links[$path] = (string) readlink(base_path($path));
    }

    return $links;
}

// An exact count, not a floor: losing one link moves this by one, and a floor
// set anywhere below the real number cannot tell that from a full set.
it('reads the index at all, so an empty listing cannot report a linked tree', function (): void {
    expect(mobileRootTrackedLinks())->toHaveCount(25);
});

// The bundle is built from mobile-app/ and ships what it finds there, so a
// directory the root cannot see was never a candidate for the build. `lang` was
// missing and shipped that way: every screen rendered in Dutch while the
// validation errors stayed English.
it('links every tree the mobile root reads, and every link resolves', function (): void {
    $broken = [];

    foreach (mobileRootTrackedLinks() as $path => $target) {
        if (! is_link(base_path($path)) || ! file_exists(base_path($path))) {
            $broken[] = $path.' -> '.$target;
        }
    }

    expect($broken)->toBe([], "These are tracked as symlinks and do not resolve:\n  ".implode("\n  ", $broken));
});

/**
 * Config files the mobile root deliberately owns rather than links, and why.
 *
 * @return array<string, string> file name => why this root needs its own
 */
function mobileRootOwnConfig(): array
{
    return [
        'auto_update.php' => 'the desktop feed is a Sparkle/Squirrel endpoint the store build must never learn; a link here would hand the phone an installer it could run',
        'nativephp.php' => 'the shells are different packages with different exclusion lists — nativephp/mobile against nativephp/desktop, which hard-conflict',
        'selfhost.php' => 'the phone is never the self-hosted server, and the keys here describe one',
        'view.php' => 'compiled views live under this root\'s own storage/, which is a different directory from the desktop root\'s',
    ];
}

// The one that shipped this way: config/currency.php was in neither list, so
// `config('currency.base')` read NULL on the phone. It agreed with the desktop
// only because BaseCurrency::installDefault() falls back to the same EUR the
// config names. Changing the shipped default made two devices of one install
// disagree about the base currency, measured in both roots.
it('gives the mobile root every config file, by link or by a declared one of its own', function (): void {
    $own = mobileRootOwnConfig();
    $unreachable = [];

    foreach ((array) glob(base_path('config/*.php')) as $desktop) {
        $name = basename((string) $desktop);
        $mobile = base_path('mobile-app/config/'.$name);

        if (is_link($mobile) || array_key_exists($name, $own)) {
            continue;
        }

        $unreachable[] = $name.(is_file($mobile) ? ' (present, but its own and undeclared)' : ' (absent — config() reads null for every key in it)');
    }

    expect($unreachable)->toBe([], implode("\n  ", [
        'The mobile Composer root cannot read these desktop config files. Symlink each into '
            .'mobile-app/config/ the way the other fourteen are, or declare it in mobileRootOwnConfig() '
            .'with the reason this root needs its own. Unreachable:',
        ...$unreachable,
    ]));
});

// A declaration for a file that is linked after all, or gone, is the same
// silence one level up: it reads as a considered decision and guards nothing.
it('declares no config of its own that is linked or absent', function (): void {
    $stale = [];

    foreach (array_keys(mobileRootOwnConfig()) as $name) {
        $mobile = base_path('mobile-app/config/'.$name);

        if (! is_file($mobile)) {
            $stale[] = $name.' is declared as this root\'s own and is not there';
        } elseif (is_link($mobile)) {
            $stale[] = $name.' is declared as this root\'s own and is a link';
        }
    }

    expect($stale)->toBe([], 'Stale declarations in mobileRootOwnConfig(): '.implode(', ', $stale));
});
