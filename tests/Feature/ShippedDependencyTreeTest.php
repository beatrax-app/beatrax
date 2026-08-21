<?php

declare(strict_types=1);

uses()->group('Phase14');

// Reads the composer.json manifest rather than resolving the tree, so the
// assertion stays fast and offline; `composer install --no-dev --dry-run` is
// the integration-level proof.

/**
 * @return array<string, mixed>
 */
function composerManifest(): array
{
    $path = base_path('composer.json');
    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

it('keeps laravel/horizon and predis/predis out of the require section', function (): void {
    $manifest = composerManifest();
    $require = $manifest['require'] ?? [];

    expect($require)->toBeArray()
        ->and(array_key_exists('laravel/horizon', $require))->toBeFalse(
            'laravel/horizon must not be in composer.json require — it ships the /horizon dashboard.',
        )
        ->and(array_key_exists('predis/predis', $require))->toBeFalse(
            'predis/predis must not be in composer.json require — the shipped build carries no Redis client.',
        );
});

it('keeps laravel/horizon and predis/predis in the require-dev section', function (): void {
    $manifest = composerManifest();
    $requireDev = $manifest['require-dev'] ?? [];

    expect($requireDev)->toBeArray()
        ->and(array_key_exists('laravel/horizon', $requireDev))->toBeTrue(
            'laravel/horizon must stay in composer.json require-dev for the local dev box.',
        )
        ->and(array_key_exists('predis/predis', $requireDev))->toBeTrue(
            'predis/predis must stay in composer.json require-dev for the local dev box.',
        );
});
