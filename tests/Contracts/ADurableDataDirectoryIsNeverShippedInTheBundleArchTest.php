<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
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
    $shells = array_keys(bundleConfigFiles());
    sort($shells);

    expect($shells)->toBe(
        ['desktop', 'mobile'],
        'A shell whose nativephp config this file cannot find is a shell every case below silently skips.',
    );
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
    expect(str_ends_with(UserDataPathService::appPath('sync'), 'storage/app/sync'))->toBeTrue(
        'The exclusion above is the literal `storage/app`, so the path service moving is the exclusion silently '
        .'stopping covering the durable data it names. It now resolves to '.UserDataPathService::appPath('sync'),
    );
});

// The matcher is the whole of what decides which directory is examined at all,
// so it is driven over both spellings the two packagers use and the near-misses
// a prefix match would swallow.
it('matches a top-level name the way both packagers do, and no more than that', function (): void {
    expect(bundleExcludesEntry(['storage/app'], 'storage'))->toBeFalse(
        'A pattern naming a subdirectory would excuse the whole tree above it, which is how storage/app shipped.',
    );
    expect(bundleExcludesEntry(['/nativephp'], 'nativephp'))->toBeTrue(
        'rsync anchors a leading slash to the transfer root, so the anchored spelling still names the top-level entry.',
    );
    expect(bundleExcludesEntry(['nativephp'], 'nativephp'))->toBeTrue('The bare spelling names it at any depth.');
    expect(bundleExcludesEntry(['*/tests'], 'tests'))->toBeFalse(
        'A pattern requiring a parent segment does not name the top-level entry, and reading it as one would '
        .'excuse a directory the packager still copies.',
    );
    expect(bundleExcludesEntry(['build'], 'build-secrets'))->toBeFalse('A prefix is not a name, and treating it as one excuses a neighbour.');
    expect(bundleExcludesEntry([], 'anything'))->toBeFalse('An empty pattern list excuses nothing.');
});

/**
 * @return array<string, string> shell label => the root that shell packages
 */
function bundleShellRoots(): array
{
    return array_map(
        static fn (string $config): string => dirname($config, 2),
        bundleConfigFiles(),
    );
}

/** @return list<string> the shell's own cleanup_exclude_files */
function bundleExcludedPaths(string $config): array
{
    return (require $config)['cleanup_exclude_files'] ?? [];
}

/**
 * Both packagers match a top-level name the same way: the desktop walker runs
 * fnmatch on the relative path, and rsync treats a leading slash as anchored to
 * the transfer root and a bare pattern as matching at any depth.
 *
 * @param  list<string>  $patterns
 */
function bundleExcludesEntry(array $patterns, string $entry): bool
{
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $entry) || fnmatch(ltrim($pattern, '/'), $entry)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a directory's CONTENTS are absent from the repository, which makes
 * whatever sits in it at build time a working artifact rather than source.
 *
 * A placeholder is not content: build-secrets/ tracks a .gitignore that ignores
 * everything beside it, so asking git whether the directory is ignored answers
 * no while every file that ever appears in it is.
 */
function bundleDirectoryHoldsNoSource(string $root, string $entry): bool
{
    $result = Process::path($root)->run(['git', 'ls-files', '--', $entry]);

    if (! $result->successful()) {
        return false;
    }

    $tracked = array_filter(explode("\n", trim($result->output())));

    foreach ($tracked as $file) {
        if (! in_array(basename($file), ['.gitignore', '.gitkeep', '.gitattributes'], true)) {
            return false;
        }
    }

    return true;
}

/**
 * @return array<string, array<string, string>> shell => directory => why it may
 *                                              reach the bundle unexcluded
 */
function bundleDirectoriesAllowedThrough(): array
{
    return [
        'desktop' => [
            'vendor' => 'the application cannot boot without it',
            // Laravel will not boot without the tree, and the one part of it
            // that holds durable user data, storage/app, is excluded by name
            // and asserted separately above.
            'storage' => 'the framework needs the tree; storage/app is excluded on its own',
        ],
        'mobile' => [
            'vendor' => 'the application cannot boot without it',
            'nativephp' => "the packager's own defaults exclude it, and it is the copy target",
            'tests' => "excluded at any depth by the packager's own defaults",
            '.phpunit.cache' => "excluded at any depth by the packager's own defaults",
            'nativephp-plugins' => 'first-party plugin source the built app loads',
            'storage' => 'the framework needs the tree; storage/app is excluded on its own',
        ],
    ];
}

// The earlier fix named one directory. The rule behind it is wider: anything
// whose contents git never sees is a working artifact, and the packager copies
// the working tree. Two signing directories and 1.6 GB of captured application
// screens were sitting outside the one name that had been fixed.
it('excludes every working directory whose contents are not in the repository', function (): void {
    $roots = bundleShellRoots();
    $configs = bundleConfigFiles();
    $allowed = bundleDirectoriesAllowedThrough();

    expect($roots)->toHaveCount(2, 'One of the two shells was not found, so its whole tree went unexamined.');

    $shipping = [];
    $examined = 0;

    foreach ($roots as $shell => $root) {
        $patterns = bundleExcludedPaths($configs[$shell]);

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($root.'/'.$entry)) {
                continue;
            }

            if (bundleExcludesEntry($patterns, $entry) || isset($allowed[$shell][$entry])) {
                continue;
            }

            $examined++;

            if (bundleDirectoryHoldsNoSource($root, $entry)) {
                $shipping[] = $shell.':'.$entry;
            }
        }
    }

    // A run that classified nothing would report a clean tree, which is the
    // answer a correctly excluded tree gives.
    expect($examined)->toBeGreaterThan(
        0,
        'Every top-level directory of both shells was excluded or declared before anything was classified, so '
        .'this rule read nothing.',
    )
        ->and($shipping)->toBe([], implode("\n  ", array_merge(
            ['These directories hold no source and are copied into a shipped bundle.',
                'Exclude them in that shell\'s cleanup_exclude_files, or declare why they belong:'],
            $shipping,
        )));
});
