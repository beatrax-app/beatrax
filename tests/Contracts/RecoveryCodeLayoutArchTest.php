<?php

declare(strict_types=1);

/*
 * Recovery codes are the only way back into an account and every screen that
 * shows them says to write them down. At 411px a two-column grid leaves ~180px
 * for a 24-character code, and `break-all` then orphans the last character
 * onto a line of its own: "X9NN-4CTG-CTRX-HPPP-PCS" / "4".
 *
 * That was fixed once, on one of the three screens. The other two kept an
 * unconditional `grid-cols-2` and kept orphaning — measured on a Galaxy S24
 * as two line boxes, the second 7px wide. This pins all of them.
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

    preg_match_all('/class="grid[^"]*"/', $source, $matches);

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
