<?php

declare(strict_types=1);

// mobile-app/ is a second Composer root that reaches the shared tree through
// tracked symlinks. A psr-4 entry whose directory is not linked in resolves
// nothing there, and nothing says so: composer emits no warning for a root that
// does not exist, the desktop root resolves the class perfectly, and the failure
// arrives on a phone as `Class "…" not found` from a container binding made in a
// module that never knew it was reading across roots.
//
// Twice now. `lang` was never symlinked and shipped that way; `database/seeders`
// was one autoload entry short when the sample-data composition root moved out
// of app/, which would have broken the settings card on the phone alone.

/**
 * Every psr-4 directory a composer manifest declares, as repo-relative paths.
 *
 * @return array<string, string> namespace => path as the manifest spells it
 */
function declaredPsr4Roots(string $manifest): array
{
    /** @var array{autoload?: array{psr-4?: array<string, string>}, autoload-dev?: array{psr-4?: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path($manifest)), true, 512, JSON_THROW_ON_ERROR);

    return array_merge(
        $composer['autoload']['psr-4'] ?? [],
        $composer['autoload-dev']['psr-4'] ?? [],
    );
}

it('reads two manifests, so a root that went unread cannot report a clean tree', function (): void {
    expect(declaredPsr4Roots('composer.json'))->not->toBeEmpty()
        ->and(declaredPsr4Roots('mobile-app/composer.json'))->not->toBeEmpty();
});

it('resolves every namespace the mobile root declares, from the mobile root', function (): void {
    $unreachable = [];

    foreach (declaredPsr4Roots('mobile-app/composer.json') as $namespace => $path) {
        if (! is_dir(base_path('mobile-app/'.$path))) {
            $unreachable[] = $namespace.' => mobile-app/'.$path;
        }
    }

    expect($unreachable)->toBe([], implode("\n  ", [
        'The mobile Composer root declares a psr-4 directory that is not there. Symlink it '
            .'the way mobile-app/Modules, mobile-app/tests and mobile-app/database/migrations are '
            .'symlinked, or drop the entry. Unreachable:',
        ...$unreachable,
    ]));
});

// The desktop root is the one every module is written against, so a namespace it
// declares and cannot reach is the same defect one root over.
it('resolves every namespace the desktop root declares', function (): void {
    $unreachable = [];

    foreach (declaredPsr4Roots('composer.json') as $namespace => $path) {
        if (! is_dir(base_path($path))) {
            $unreachable[] = $namespace.' => '.$path;
        }
    }

    expect($unreachable)->toBe([], 'A psr-4 root points at a directory that does not exist: '.implode(', ', $unreachable));
});

// A namespace both roots declare must mean the same tree in both, or a class
// resolves to one file on the desktop and another on the phone.
it('gives a shared namespace the same tree in both roots', function (): void {
    $desktop = declaredPsr4Roots('composer.json');
    $mobile = declaredPsr4Roots('mobile-app/composer.json');

    $shared = array_intersect_key($desktop, $mobile);
    expect($shared)->not->toBeEmpty('The two manifests share no namespace at all, so the comparison below read nothing.');

    $divergent = [];
    foreach (array_keys($shared) as $namespace) {
        $desktopReal = realpath(base_path($desktop[$namespace]));
        $mobileReal = realpath(base_path('mobile-app/'.$mobile[$namespace]));

        if ($desktopReal === false || $mobileReal === false || $desktopReal !== $mobileReal) {
            $divergent[] = $namespace.': '.($desktop[$namespace]).' vs mobile-app/'.($mobile[$namespace]);
        }
    }

    expect($divergent)->toBe([], 'A namespace resolves to a different tree per root: '.implode(', ', $divergent));
});
