<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#grid-cols-2-recovery-codes-at-411px
 */

// The three views that LAY THE CODES OUT, named rather than detected: two other
// blades and a settings section mention recovery codes without drawing a grid of
// them. This rule reads the three files it names and nothing else, so a fourth
// view drawing codes has to be added here as well.
/**
 * @return list<string>
 */
function recoveryCodeViews(): array
{
    return [
        'Modules/Auth/Resources/views/livewire/recovery-codes-display.blade.php',
        'Modules/Auth/Resources/views/livewire/manage-user-page.blade.php',
        'Modules/Mobile/Resources/views/livewire/mobile-import-bootstrap.blade.php',
    ];
}

// A bare grid-cols-2 with no single-column base is the defect: it applies at
// every width, phone included.
/** @return list<string> the class attribute of every grid the source keeps at two columns */
function recoveryCodeTwoColumnGridsIn(string $source): array
{
    return array_values(array_filter(
        PatternScan::all('/class="grid[^"]*"/', $source)[0],
        static fn (string $class): bool => str_contains($class, 'grid-cols-2')
            && ! str_contains($class, 'grid-cols-1'),
    ));
}

it('never puts recovery codes in two columns on a phone', function (string $view): void {
    $offenders = recoveryCodeTwoColumnGridsIn((string) file_get_contents(base_path($view)));

    expect($offenders)->toBe([], sprintf(
        "%s has %d grid(s) that stay two columns at phone width:\n  %s",
        $view,
        count($offenders),
        implode("\n  ", $offenders),
    ));
})->with(recoveryCodeViews());

it('keeps every named view real and still drawing a grid of codes', function (string $view): void {
    $path = base_path($view);

    expect(is_file($path))->toBeTrue($view.' is one of the three views this rule reads and it is gone. A missing file reads as an empty source, which reads as a view with no two-column grid — so the rule passes on a screen nobody checked.');

    expect(PatternScan::matches('/class="grid[^"]*"/', (string) file_get_contents($path)))
        ->toBeTrue($view.' no longer draws a grid at all, so the rule above has nothing to judge. Move the entry to wherever the code layout went, or drop it.');
})->with(recoveryCodeViews());

it('reads a grid that stays two columns and spares one with a single-column base', function (): void {
    $bare = '<div class="grid grid-cols-2 gap-2">';
    // The shape all three views use, and the one this rule asks for.
    $responsive = '<div class="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3">';
    $notAGrid = '<div class="flex grid-cols-2">';

    expect(recoveryCodeTwoColumnGridsIn($bare))->toBe(['class="grid grid-cols-2 gap-2"'])
        ->and(recoveryCodeTwoColumnGridsIn($responsive))->toBe([])
        ->and(recoveryCodeTwoColumnGridsIn($notAGrid))->toBe([]);
});

it('lets a recovery code wrap rather than overflow its box', function (string $view): void {
    $source = (string) file_get_contents(base_path($view));

    expect($source)->toContain('break-all');
})->with(recoveryCodeViews());
