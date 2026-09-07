<?php

declare(strict_types=1);

// electron-builder's WinPackager::shouldSignFile falls back to `.exe` when
// `win.signExts` is absent, and the desktop bundle carries a whole PHP runtime
// of DLLs under extraResources. A signed installer says nothing about them,
// and store certification is per file.

/** @return array<string, string> workflow basename => contents */
function windowsSigningWorkflows(): array
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $directory = base_path($candidate);

        if (! is_dir($directory)) {
            continue;
        }

        $found = [];

        foreach (['release.yml', 'release-build.yml'] as $name) {
            $path = $directory.'/'.$name;

            if (is_file($path)) {
                $found[$name] = (string) file_get_contents($path);
            }
        }

        return $found;
    }

    return [];
}

function windowsSigningPatchScript(): string
{
    foreach (['scripts', '../scripts'] as $candidate) {
        $path = base_path($candidate.'/nativephp_sign_every_pe_in_the_package.php');

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('finds both release workflows it is written against', function (): void {
    expect(array_keys(windowsSigningWorkflows()))->toBe(['release.yml', 'release-build.yml']);
});

it('reads the package for an unsigned binary, in both workflows', function (string $name): void {
    $workflow = windowsSigningWorkflows()[$name] ?? '';

    expect($workflow)->toContain('Get-AuthenticodeSignature');
    expect($workflow)->toContain('unpacked');
})->with(['release.yml', 'release-build.yml']);

// A walk that finds nothing and passes reads exactly like a package that is
// fully signed, and the difference is the whole value of the check.
it('refuses a walk that read no binaries at all', function (string $name): void {
    expect(windowsSigningWorkflows()[$name] ?? '')->toContain('$checked -eq 0');
})->with(['release.yml', 'release-build.yml']);

it('configures electron-builder to sign more than the exe', function (): void {
    $script = windowsSigningPatchScript();

    expect($script)->not->toBe('');

    foreach (["'.exe'", "'.dll'", "'.node'"] as $extension) {
        expect($script)->toContain($extension);
    }
});

it('runs that patch before every build', function (): void {
    /** @var array<string, mixed> $hooks */
    $hooks = config('nativephp.prebuild', []);

    expect(implode("\n", array_map(strval(...), $hooks)))
        ->toContain('nativephp_sign_every_pe_in_the_package.php');
});
