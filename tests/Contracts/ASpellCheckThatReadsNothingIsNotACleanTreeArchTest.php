<?php

declare(strict_types=1);

// The walker skips dot-directories by default, so `.docs/` -- the 242 pages
// this repository keeps its technical record in -- was never read by the typos
// gate, in CI or anywhere else. It reported a clean tree the whole time, which
// is what reading nothing looks like from the outside.
//
// Pinned in the config rather than as a `--hidden` flag, because the shared
// hygiene workflow runs the binary with no arguments at all: a flag would have
// covered the machine that remembered to type it and nothing else.

function typosConfig(): string
{
    return (string) file_get_contents(base_path('typos.toml'));
}

it('reads the documentation tree, which is a dot-directory the walker skips by default', function (): void {
    expect(typosConfig())->toMatch('/^\s*ignore-hidden\s*=\s*false\s*$/m');
});

it('names a checker a machine may not have, rather than passing when it is absent', function (): void {
    $script = base_path('bin/typos.sh');

    expect(is_file($script))->toBeTrue()
        ->and(is_executable($script))->toBeTrue();

    // The whole point of the wrapper: `typos` is neither a Composer nor an npm
    // dependency, so a machine without it runs nothing and exits 0 -- which is
    // indistinguishable from a clean tree.
    $body = (string) file_get_contents($script);

    expect($body)->toContain('command -v typos')
        ->toContain('brew install typos-cli')
        ->toContain('exit 127');
});

it('is reachable by the name the contributing guide tells a reader to run', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer)->toBeArray()
        ->and($composer['scripts']['typos'] ?? null)->toBe('bash bin/typos.sh');

    expect((string) file_get_contents(base_path('AGENTS.md')))->toContain('composer typos');
});
