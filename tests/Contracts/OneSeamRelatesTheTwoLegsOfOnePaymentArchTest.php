<?php

declare(strict_types=1);

// A settled leg and the rate beside it are one derivation, and it was written
// twice: once in the value object and once in the stage. The stage's copy took
// each adapter's sign on trust and stored a negative exchange rate.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-expense-that-settled-as-income-because-one-leg-was-read-verbatim

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function settledLegSeamRepoRoot(): string
{
    return dirname((string) realpath(base_path('Modules')));
}

/**
 * @return array<string, string> repo-relative path => contents
 */
function settledLegSeamSources(): array
{
    $root = settledLegSeamRepoRoot();

    $sources = [];
    foreach (['Modules', 'app', 'database', 'config', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root.'/', '', $file->getPathname());
            if (str_contains($path, '/tests/')) {
                continue;
            }
            $sources[$path] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

it('derives the effective rate between two legs in exactly one place', function (): void {
    $seam = 'Modules/Ledger/Public/ValueObjects/TransactionAmount.php';

    $deriving = [];
    foreach (settledLegSeamSources() as $path => $source) {
        if ($path !== $seam && str_contains($source, 'Rate::between(')) {
            $deriving[] = $path;
        }
    }

    expect($deriving)->toBe([], implode("\n  ", [
        'The rate relating a native leg to its settled one is derived by',
        'TransactionAmount::relate(), which also gives the two legs the one sign a',
        'single movement has. A second derivation takes its numerator on the',
        "adapter's word, and a settled credit over a native debit stores a negative",
        'rate and an expense that reads as income. Offenders:',
        ...$deriving,
    ]));
});

it('keeps the import seam routing its settled pair through that one place', function (): void {
    $stage = settledLegSeamSources()['Modules/Import/Public/Pipeline/NormalizeStage.php'] ?? '';

    expect($stage)->not->toBe('');
    expect(str_contains($stage, 'TransactionAmount::relate('))->toBeTrue(implode("\n  ", [
        'NormalizeStage is the single stage every import format and the receipts',
        'bridge reach the settled columns through, so it is where the invariant',
        'becomes unreachable rather than merely absent. Building the pair inline',
        'here puts each adapter back in charge of the sign.',
    ]));
});
