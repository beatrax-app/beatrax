<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

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
