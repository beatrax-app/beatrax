<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Tax\Internal\Support\FilingSeason;

it('resolves January to April to the previous year, the return still being filed', function (int $month): void {
    expect(FilingSeason::defaultYear(CarbonImmutable::create(2026, $month, 15)))->toBe(2025);
})->with([1, 2, 3, 4]);

it('resolves May to December to the current year', function (int $month): void {
    expect(FilingSeason::defaultYear(CarbonImmutable::create(2026, $month, 15)))->toBe(2026);
})->with([5, 6, 7, 8, 9, 10, 11, 12]);

it('turns over on the boundary between April and May', function (): void {
    expect(FilingSeason::defaultYear(CarbonImmutable::create(2026, 4, 30)))->toBe(2025)
        ->and(FilingSeason::defaultYear(CarbonImmutable::create(2026, 5, 1)))->toBe(2026);
});

it('has the three tax surfaces reading the boundary from FilingSeason', function (): void {
    // The card, the page it links to and the tag picker have to resolve the
    // same year or the dashboard quotes a total /tax does not show, and only
    // between January and April.
    $surfaces = [
        'Modules/Tax/Public/Http/Livewire/TaxSummaryCard.php',
        'Modules/Tax/Internal/Http/Livewire/TaxPage.php',
        'Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php',
    ];

    foreach ($surfaces as $surface) {
        $path = dirname(__DIR__, 4).'/'.$surface;

        expect(is_file($path))->toBeTrue("$surface has moved; point this test at its new path.")
            ->and((string) file_get_contents($path))->toContain('FilingSeason::defaultYear');
    }
});

it('is the only place the seasonal rule is written down', function (): void {
    $repoRoot = dirname(__DIR__, 4);
    $home = 'Modules/Tax/Internal/Support/FilingSeason.php';

    $offenders = [];
    foreach (['Modules', 'app', 'resources'] as $directory) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($repoRoot.'/', '', $file->getPathname());

            // A test may spell the rule out; that is what pins it.
            if ($relative === $home || str_contains($relative, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/->month\s*<=?\s*\d/', $source) === 1 && preg_match('/->year\s*-\s*1/', $source) === 1) {
                $offenders[] = $relative;
            }
        }
    }
    sort($offenders);

    expect($offenders)->toBe(
        [],
        'The filing-season rule is spelled out again outside '.$home.', so the boundary month can now '
        ."change in one place and not the other. Call FilingSeason::defaultYear() instead:\n  "
        .implode("\n  ", $offenders),
    );
});
