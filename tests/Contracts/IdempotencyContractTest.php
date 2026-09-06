<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

// Every adapter, imported twice. The pair below is the same claim over a
// stronger corpus, and it is split off rather than folded in because five of
// these six rows have no overlapping pair to import: running them through a
// case called "an overlapping period" made the weaker claim read as the
// stronger one, in the description and in the failure message alike.
dataset('idempotent_adapters', [
    'asn-csv' => ['adapterFormat' => 'asn-csv', 'fixture' => __DIR__.'/../fixtures/asn-sample-1.csv'],
    'camt053' => ['adapterFormat' => 'camt053', 'fixture' => __DIR__.'/../fixtures/asn-camt053-sample-1.xml'],
    'mt940' => ['adapterFormat' => 'mt940', 'fixture' => __DIR__.'/../fixtures/asn-mt940-sample-1.sta'],
    'ics-pdf' => ['adapterFormat' => 'ics-pdf', 'fixture' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf'],
    'paypal-csv' => ['adapterFormat' => 'paypal-csv', 'fixture' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'],
    // The receipt path dedupes on the file_imports UNIQUE over
    // (user_id, provider_message_id) rather than on the fingerprint alone.
    'paypal-receipt-eml' => ['adapterFormat' => 'eml', 'fixture' => __DIR__.'/../../Modules/Receipts/tests/fixtures/paypal/current-receipt.eml'],
]);

/**
 * The adapters whose corpus ships a genuine overlap: a second file covering a
 * later period that repeats part of the first. Only the CSV pair does today,
 * and this is where a CAMT or MT940 pair joins it. Read by the dataset and by
 * the reader that holds the dataset to it, so the two cannot disagree.
 *
 * @return array<string, array{adapterFormat: string, overlapBase: string, overlapNext: string}>
 */
function idempotencyOverlapPairs(): array
{
    return [
        'asn-csv' => [
            'adapterFormat' => 'asn-csv',
            'overlapBase' => __DIR__.'/../fixtures/asn-month-a.csv',
            'overlapNext' => __DIR__.'/../fixtures/asn-month-a-and-b.csv',
        ],
    ];
}

dataset('overlapping_period_adapters', idempotencyOverlapPairs());

function idempotencyFormatHint(string $adapterFormat): ?BankCsvFormatHint
{
    return match ($adapterFormat) {
        'asn-csv' => BankCsvFormatHint::Asn,
        default => null,
    };
}

it('produces zero new rows when the same file is imported twice', function (string $adapterFormat, string $fixture): void {
    $this->seedFixtureUserAndAccount();
    $importer = $this->app->make(RunsImports::class);
    $hint = idempotencyFormatHint($adapterFormat);

    $first = $importer->runAndConfirm($fixture, $adapterFormat, $this->fixtureUser, formatHint: $hint);
    $second = $importer->runAndConfirm($fixture, $adapterFormat, $this->fixtureUser, formatHint: $hint);

    // Read before the two verdicts: a fixture that inserted nothing satisfies
    // "the second import inserts zero" without the adapter deduplicating
    // anything at all, which is the same green a working adapter gives.
    expect($first->inserted)->toBeGreaterThan(
        0,
        $adapterFormat.': the first import of '.basename($fixture).' inserted no rows, so the two assertions '
        .'below are about an import that never happened.'
    );

    expect($second->inserted)->toBe(
        0,
        $adapterFormat.': re-importing the same file inserted '.$second->inserted.' rows.'
    );

    expect($second->duplicates)->toBe(
        $first->inserted,
        $adapterFormat.': the second import counted '.$second->duplicates.' duplicates against '
        .$first->inserted.' rows the first one wrote. Every row should have been recognised.'
    );
})->with('idempotent_adapters')->group('phase-2');

it('inserts only the rows a later file adds when its period overlaps one already imported', function (string $adapterFormat, string $overlapBase, string $overlapNext): void {
    $this->seedFixtureUserAndAccount();
    $importer = $this->app->make(RunsImports::class);
    $hint = idempotencyFormatHint($adapterFormat);

    $first = $importer->runAndConfirm($overlapBase, $adapterFormat, $this->fixtureUser, formatHint: $hint);
    $second = $importer->runAndConfirm($overlapNext, $adapterFormat, $this->fixtureUser, formatHint: $hint);

    expect($overlapBase)->not->toBe(
        $overlapNext,
        $adapterFormat.': this dataset is for a genuine overlap pair. A row naming the same file twice belongs '
        .'in idempotent_adapters, where re-importing one file is the whole claim.'
    );

    expect($first->inserted)->toBeGreaterThan(
        0,
        $adapterFormat.': the base import of '.basename($overlapBase).' inserted no rows, so the comparisons '
        .'below are against nothing.'
    );

    expect($second->inserted)->toBeLessThan(
        $first->inserted,
        $adapterFormat.': the overlapping import inserted '.$second->inserted.' rows against the base import\'s '
        .$first->inserted.'. The shared period should have been recognised and skipped.'
    );

    expect($second->duplicates)->toBeGreaterThan(
        0,
        $adapterFormat.': the overlapping import counted no duplicates at all, so the rows it did not insert '
        .'were not insertions it recognised.'
    );
})->with('overlapping_period_adapters')->group('phase-2');

// The dataset above covers one adapter of six, and a corpus grown to hold a
// second pair should exercise it rather than sit unread. This is the reader
// that says so, and it also stops the dataset going empty: with no row, the
// case above passes by never running.
it('ships a readable file for every overlapping pair it claims to exercise', function (): void {
    $pairs = idempotencyOverlapPairs();

    expect($pairs)->not->toBe(
        [],
        'No adapter ships an overlapping fixture pair any more, so the case above runs over an empty dataset '
        .'and passes without importing anything.'
    );

    $missing = [];

    foreach ($pairs as $adapterFormat => $pair) {
        foreach (['overlapBase', 'overlapNext'] as $half) {
            if (! is_file($pair[$half])) {
                $missing[] = $adapterFormat.' '.$half.' → '.$pair[$half];
            }
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'These overlap fixtures are named and not on disk, so the import they stand for cannot run:',
        ...$missing,
    ]));
});
