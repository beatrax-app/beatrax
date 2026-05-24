<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * Proves the full post-rename artisan-signature surface:
 *
 *  1. Every former `diederik:*` command name now resolves under its
 *     `beatrax:*` signature in the console kernel registry. The roster
 *     is enumerated as a Pest dataset so a missing rename surfaces as
 *     a single failure per command, not a single failure for the
 *     whole batch.
 *
 *  2. No `diederik:*` name resolves anywhere in the kernel — regression
 *     guard against a partial rename leaving an old signature in place.
 *
 *  3. The composer manifest `name` field is `beatrax/beatrax`.
 *
 * Artisan facade usage is permitted inside `tests/` (the facade ban in
 * the noAuthFacadeOrHelper invariant excludes test files; see Modules/
 * scope filter in BoundaryArchTest).
 */

dataset('renamed_commands', [
    ['beatrax:doctor'],
    ['beatrax:failed-jobs'],
    ['beatrax:install'],
    ['beatrax:reset-password'],
    ['beatrax:rederive-fingerprints'],
]);

it('resolves every renamed command under its beatrax:* signature', function (string $name): void {
    $registered = array_keys(Artisan::all());

    expect($registered)->toContain($name);
})->with('renamed_commands');

it('does not leave any diederik:* signature in the console kernel', function (): void {
    $leftover = array_filter(
        array_keys(Artisan::all()),
        static fn (string $name): bool => str_starts_with($name, 'diederik:'),
    );

    expect(array_values($leftover))->toBe(
        [],
        'No diederik:* signature should remain after the rename. Found: '.implode(', ', $leftover),
    );
});

it('declares the composer package name as beatrax/beatrax', function (): void {
    $manifestPath = base_path('composer.json');
    $manifest = json_decode((string) file_get_contents($manifestPath), associative: true);

    expect($manifest)->toBeArray();
    expect($manifest['name'] ?? null)->toBe('beatrax/beatrax');
});
