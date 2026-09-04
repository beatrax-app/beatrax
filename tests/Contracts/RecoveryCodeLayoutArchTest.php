<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#grid-cols-2-recovery-codes-at-411px
 */

/** @return list<string> */
function recoveryCodeViews(): array
{
    return [
        'Modules/Auth/Resources/views/livewire/recovery-codes-display.blade.php',
        'Modules/Auth/Resources/views/livewire/manage-user-page.blade.php',
        'Modules/Mobile/Resources/views/livewire/mobile-import-bootstrap.blade.php',
    ];
}

it('never puts recovery codes in two columns on a phone', function (string $view): void {
    $source = (string) file_get_contents(base_path($view));

    $matches = PatternScan::all('/class="grid[^"]*"/', $source);

    // A bare grid-cols-2 with no single-column base is the defect: it applies
    // at every width, phone included.
    $offenders = array_values(array_filter(
        $matches[0],
        static fn (string $class): bool => str_contains($class, 'grid-cols-2')
            && ! str_contains($class, 'grid-cols-1'),
    ));

    expect($offenders)->toBe([], sprintf(
        "%s has %d grid(s) that stay two columns at phone width:\n  %s",
        $view,
        count($offenders),
        implode("\n  ", $offenders),
    ));
})->with(recoveryCodeViews());

it('lets a recovery code wrap rather than overflow its box', function (string $view): void {
    $source = (string) file_get_contents(base_path($view));

    expect($source)->toContain('break-all');
})->with(recoveryCodeViews());
