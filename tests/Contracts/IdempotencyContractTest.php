<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;

dataset('idempotent_adapters', [
    'asn-csv' => [
        'adapterFormat' => 'asn-csv',
        'fixture' => __DIR__.'/../fixtures/asn-sample-1.csv',
        'overlapBase' => __DIR__.'/../fixtures/asn-month-a.csv',
        'overlapNext' => __DIR__.'/../fixtures/asn-month-a-and-b.csv',
    ],
    // The CAMT + MT940 fixture corpora do not yet ship matching
    // overlap-period pairs, so the overlap variants fall back to the
    // same-file pattern. Re-importing the same file produces zero new
    // rows, which is still the strongest possible idempotency claim
    // for the data available today. Replace with a real overlap pair
    // the moment one lands in tests/fixtures/.
    'asn-camt053' => [
        'adapterFormat' => 'asn-camt053',
        'fixture' => __DIR__.'/../fixtures/asn-camt053-sample-1.xml',
        'overlapBase' => __DIR__.'/../fixtures/asn-camt053-sample-1.xml',
        'overlapNext' => __DIR__.'/../fixtures/asn-camt053-sample-1.xml',
    ],
    'asn-mt940' => [
        'adapterFormat' => 'asn-mt940',
        'fixture' => __DIR__.'/../fixtures/asn-mt940-sample-1.sta',
        'overlapBase' => __DIR__.'/../fixtures/asn-mt940-sample-1.sta',
        'overlapNext' => __DIR__.'/../fixtures/asn-mt940-sample-1.sta',
    ],
    'ics-pdf' => [
        'adapterFormat' => 'ics-pdf',
        'fixture' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf',
        'overlapBase' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf',
        'overlapNext' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf',
    ],
    'paypal-csv' => [
        'adapterFormat' => 'paypal-csv',
        'fixture' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
        'overlapBase' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
        'overlapNext' => __DIR__.'/../../Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
    ],
    // Receipt-path row: same .eml dropped twice must produce zero
    // additional canonical transactions on the second drop. Backed
    // by the file_imports UNIQUE on (user_id, provider_message_id)
    // and the FingerprintComposer v3 dedup tuple.
    'paypal-receipt-eml' => [
        'adapterFormat' => 'eml',
        'fixture' => __DIR__.'/../../Modules/Receipts/tests/fixtures/paypal/current-receipt.eml',
        'overlapBase' => __DIR__.'/../../Modules/Receipts/tests/fixtures/paypal/current-receipt.eml',
        'overlapNext' => __DIR__.'/../../Modules/Receipts/tests/fixtures/paypal/current-receipt.eml',
    ],
]);

it('produces zero new rows when the same file is imported twice', function (
    string $adapterFormat,
    string $fixture,
    string $overlapBase,
    string $overlapNext,
): void {
    $this->seedFixtureUserAndAccount();
    $importer = $this->app->make(RunsImports::class);

    $first = $importer->runAndConfirm($fixture, $adapterFormat, $this->fixtureUser);
    $second = $importer->runAndConfirm($fixture, $adapterFormat, $this->fixtureUser);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($first->inserted);
})->with('idempotent_adapters')->group('phase-2');

it('produces zero new rows when an overlapping period is imported', function (
    string $adapterFormat,
    string $fixture,
    string $overlapBase,
    string $overlapNext,
): void {
    $this->seedFixtureUserAndAccount();
    $importer = $this->app->make(RunsImports::class);

    $first = $importer->runAndConfirm($overlapBase, $adapterFormat, $this->fixtureUser);
    $second = $importer->runAndConfirm($overlapNext, $adapterFormat, $this->fixtureUser);

    if ($overlapBase === $overlapNext) {
        // Same-file fallback (CAMT / MT940 corpora ship no overlap pair
        // yet): the strongest idempotency claim is that the second
        // import inserts zero new rows.
        expect($second->inserted)->toBe(0);
        expect($second->duplicates)->toBe($first->inserted);
    } else {
        // True overlap-period pair (CSV ships one today): the second
        // import inserts fewer rows than the first AND surfaces a
        // positive duplicate count for the overlapping period.
        expect($second->inserted)->toBeLessThan($first->inserted);
        expect($second->duplicates)->toBeGreaterThan(0);
    }
})->with('idempotent_adapters')->group('phase-2');
