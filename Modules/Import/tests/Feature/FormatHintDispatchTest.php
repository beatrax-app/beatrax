<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;

// CAMT.053 and MT940 are unambiguous, so the hint is null on those paths. Every
// bank exports its own CSV dialect, so a CSV import without a hint has to be
// refused at the contract boundary, not only by the wizard's own validation.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

it('passes asn-csv format hint through RunsImports to ImportPipeline', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    $preview = $this->importer->runFromUpload(
        $fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    expect($preview->importRunId)->toBeGreaterThan(0);
    expect($preview->rows)->not->toBeEmpty();

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($preview->importRunId);
    expect($run->source_format)->toBe('asn-csv');
});

it('passes ing-csv format hint through RunsImports to ImportPipeline', function (): void {
    // No ing-csv adapter is registered. RunImport still creates the ImportRun
    // row before the pipeline runs, and the unsupported-format error has to
    // surface as one error preview row rather than blowing up the call.
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    $preview = $this->importer->runFromUpload(
        $fixture,
        'ing-csv',
        $this->fixtureUser,
        'ing-sample.csv',
        BankCsvFormatHint::Ing,
    );

    expect($preview->importRunId)->toBeGreaterThan(0);

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($preview->importRunId);
    expect($run->source_format)->toBe('ing-csv');
});

it('raises InvalidArgumentException when a CSV format is requested without a format hint', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    expect(fn () => $this->importer->runFromUpload(
        $fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        null,
    ))->toThrow(InvalidArgumentException::class, 'CSV imports require a format hint.');
});
