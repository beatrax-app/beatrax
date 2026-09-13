<?php

declare(strict_types=1);

// pcov instruments ONE directory tree, and left to detect it, it picks `app/`.
// `composer test:coverage` did not say otherwise, so a local run measured 22
// files beside the ~7,000 that hold the product and reported 5 covered
// statements against 202,690 — a 0.0% that reads as "nothing is tested" rather
// than as "nothing was measured". CI has always overridden it; only the local
// script was wrong, which is the half nobody's gate looks at.

/** @return array<string, string> directive => value, over every ini in the directory */
function coverageIniDirectives(string $directory): array
{
    $directives = [];

    foreach (glob($directory.'/*.ini') ?: [] as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $directives[trim($name)] = trim($value);
        }
    }

    return $directives;
}

it('points the coverage script at the whole product, not at one directory', function (): void {
    /** @var array{scripts?: array<string, string|list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    $script = $composer['scripts']['test:coverage'] ?? null;

    expect($script)->toBeArray('test:coverage has to set the scan directory before it runs, so it is a list of steps.');

    $steps = implode(' ', (array) $script);

    // One needle per call: toContain treats every further argument as another
    // needle, so an explanation passed beside one is searched for as text.
    expect($steps)->toContain('PHP_INI_SCAN_DIR')
        ->and($steps)->toContain('tools/test-php-ini')
        ->and($steps)->toContain('tools/coverage-php-ini');
});

it('sets the directives the scan directory exists to set', function (): void {
    $directives = coverageIniDirectives(base_path('tools/coverage-php-ini'));

    expect($directives)->not->toBe([], 'The coverage scan directory holds no ini file at all.');

    expect($directives['pcov.directory'] ?? null)
        ->toBe('.', 'Anything else measures a subtree; `.` is the repository root because composer runs from there.')
        ->and($directives['pcov.enabled'] ?? null)
        ->toBe('1', 'tools/test-php-ini turns pcov off for an ordinary test run, so a coverage run has to turn it back on.');
});

it('quotes a pattern php.ini would otherwise read as arithmetic', function (): void {
    $directives = coverageIniDirectives(base_path('tools/coverage-php-ini'));
    $exclude = $directives['pcov.exclude'] ?? '';

    // Unquoted, php.ini reads ~, | and () as bitwise operators: the pattern
    // evaluates to -1, excludes nothing, and nothing anywhere says so.
    expect($exclude)->toStartWith('"', 'An unquoted pcov.exclude is parsed as an expression, not as a regular expression.')
        ->and($exclude)->toEndWith('"')
        ->and(trim($exclude, '"'))->toContain('vendor');

    expect($exclude)->not->toBe('-1');
});
