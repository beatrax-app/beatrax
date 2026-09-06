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
    // Every root that ships PHP or Blade. "Exactly one place" is a claim about
    // the application, and the narrower five could not see a view, a bootstrap
    // file or a release script deriving the rate for itself.
    foreach (['Modules', 'app', 'database', 'config', 'routes', 'resources', 'bootstrap', 'lang', 'scripts'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }
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

const SETTLED_LEG_SEAM = 'Modules/Ledger/Public/ValueObjects/TransactionAmount.php';

it('derives the effective rate between two legs in exactly one place', function (): void {
    $sources = settledLegSeamSources();

    expect(count($sources))->toBeGreaterThan(2000, 'The walk read almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    // The exemption is one file, and a pin that excuses nothing reads as
    // considered: the seam has to still be doing the derivation it is spared for.
    expect(str_contains($sources[SETTLED_LEG_SEAM] ?? '', 'Rate::between('))
        ->toBeTrue(SETTLED_LEG_SEAM.' is the one site exempted from this rule and it no longer derives a rate. Move the exemption to wherever the derivation went, or delete it.');

    $deriving = [];
    foreach ($sources as $path => $source) {
        if ($path !== SETTLED_LEG_SEAM && str_contains($source, 'Rate::between(')) {
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
