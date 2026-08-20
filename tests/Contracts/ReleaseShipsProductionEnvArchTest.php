<?php

declare(strict_types=1);

/*
 * .env.example carries APP_DEBUG=true so local work has a usable default, and
 * the release workflow stages the shipped bundle's .env by copying it. Every
 * release artefact therefore went out with debug on.
 *
 * Found on a device: /user/confirm-password answered with Laravel's debug page,
 * disclosing LARAVEL 13.24.0, PHP 8.5.9, the full exception trace and file
 * paths. The .env pulled off that build read APP_DEBUG=true.
 */

// The suite runs from BOTH composer roots, and from mobile-app/ the workflow
// sits one level up. Resolving only the desktop path made file_get_contents
// return false there, the loop find nothing, and the guard pass by reading
// an empty string.
function releaseWorkflowSource(): string
{
    foreach (['.github/workflows/release.yml', '../.github/workflows/release.yml'] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('never stages a shipped bundle without forcing production and debug off', function (): void {
    $workflow = releaseWorkflowSource();

    expect($workflow)->not->toBe('', 'release.yml was not found from either composer root');

    $lines = explode("\n", $workflow);

    $unhardened = [];

    foreach ($lines as $index => $line) {
        if (! str_contains($line, 'cp .env.example .env')) {
            continue;
        }

        // The next handful of lines must neutralise the template's debug
        // default. A run step that only copies is one that ships it.
        $window = implode("\n", array_slice($lines, $index, 8));

        if (str_contains($window, 'APP_DEBUG=false')) {
            continue;
        }

        // The Larastan/Pest step builds nothing that ships, so it is allowed
        // to run on the template as-is.
        if (str_contains(implode("\n", array_slice($lines, max(0, $index - 6), 8)), 'Larastan')) {
            continue;
        }

        $unhardened[] = 'release.yml line '.($index + 1);
    }

    expect($unhardened)->toBe([], sprintf(
        "These steps stage a bundled .env from the template without forcing APP_DEBUG=false:\n  - %s",
        implode("\n  - ", $unhardened),
    ));
});

it('still leaves the local template debuggable, which is what it is for', function (): void {
    $template = is_file(base_path('.env.example'))
        ? base_path('.env.example')
        : base_path('../.env.example');

    expect(is_file($template))->toBeTrue('.env.example was not found from either composer root')
        ->and((string) file_get_contents($template))->toContain('APP_DEBUG=true');
});
