<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#app_debugtrue-in-the-shipped-bundle
 */
// Both composer roots run this suite, and from mobile-app/ the workflow sits
// one level up. Resolving only the desktop path made the guard pass by reading
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
    $staging = 0;

    foreach ($lines as $index => $line) {
        if (! str_contains($line, 'cp .env.example .env')) {
            continue;
        }

        $staging++;

        // A run step that only copies is one that ships the template's debug
        // default, so the neutralising lines must follow within the window.
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

    // The whole rule keys on one literal command. A workflow that stages its
    // .env some other way -- a heredoc, a composite action, `install -m` --
    // gives this loop nothing to examine and an empty offender list, which
    // reads exactly like a release that hardens every bundle it builds.
    expect($staging)->toBeGreaterThan(0, 'release.yml no longer stages a bundled .env with `cp .env.example .env`, so this rule examined no step at all. Point it at whatever command stages the file now.');

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
