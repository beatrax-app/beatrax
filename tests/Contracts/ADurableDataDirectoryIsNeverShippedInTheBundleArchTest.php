<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// storage/app is where UserDataPathService keeps durable user data: the sync
// identity key-files, the group data keys, the secrets, the backups, the
// imported statements, the dropped mail. At runtime appPath() resolves to
// NATIVEPHP_STORAGE_PATH on the desktop and into persisted_data/ on a phone —
// never into the bundle — so the copy inside a build is read by nothing.
//
// It shipped anyway. An installed iPhone build carried
// storage/app/sync/identity/*.enc byte-identical to the build machine's own,
// one file per user id that machine had ever run, and the desktop tree holds a
// whole database backup plus 23 imported statements and 26 dropped emails.
// Every one of those is gitignored, which is exactly the trap: gitignore bounds
// what reaches git, never what reaches a build.
//
// Both shells are checked from either Composer root, because this file is
// shared — mobile-app/tests is a symlink to this directory — and each root
// answers config() for its own shell only.

/**
 * @return array<string, string> shell label => path to its nativephp config
 */
function bundleConfigFiles(): array
{
    $candidates = [
        'desktop' => [base_path('config/nativephp.php'), base_path('../config/nativephp.php')],
        'mobile' => [base_path('mobile-app/config/nativephp.php'), base_path('config/nativephp.php')],
    ];

    $found = [];

    foreach ($candidates as $shell => $paths) {
        foreach ($paths as $path) {
            // The mobile root's own config is also the second desktop
            // candidate, so the marker decides which shell a file belongs to
            // rather than the order it was probed in.
            $real = realpath($path);

            if ($real === false) {
                continue;
            }

            $isMobile = str_contains((string) file_get_contents($real), 'Mobile-app-specific NativePHP config');

            if ($isMobile === ($shell === 'mobile')) {
                $found[$shell] = $real;

                break;
            }
        }
    }

    return $found;
}

it('finds both shells to check, from whichever root is running', function (): void {
    expect(array_keys(bundleConfigFiles()))->toEqualCanonicalizing(['desktop', 'mobile']);
});

// toContain takes needles, never a trailing explanation: a message passed
// alongside becomes a second needle and fails against a correct list. The
// shells that came up short are collected and named in one comparison instead.
it('keeps durable user data out of every shipped bundle', function (): void {
    $shipping = [];

    foreach (bundleConfigFiles() as $shell => $path) {
        $excluded = (require $path)['cleanup_exclude_files'] ?? [];

        if (! in_array('storage/app', $excluded, true)) {
            $shipping[] = $shell;
        }
    }

    expect($shipping)->toBe([], 'these bundles ship storage/app: '.implode(', ', $shipping));
});

// The exclusion is a literal, and the service is free to move where it keeps
// its durable data. Pinning that the two still name the same place keeps the
// exclusion honest rather than merely present.
it('excludes the directory the path service actually resolves to', function (): void {
    expect(UserDataPathService::appPath('sync'))->toEndWith('storage/app/sync');
});
