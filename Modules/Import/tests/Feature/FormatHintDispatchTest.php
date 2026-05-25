<?php

declare(strict_types=1);

use InvalidArgumentException;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;

/*
 * Verifies the optional format-hint parameter on the public RunsImports
 * contract reaches the ImportPipeline and is honoured at adapter dispatch
 * time.
 *
 * The hint is the load-bearing signal the wizard sends when the user
 * chose the CSV branch on the bank connector step. CAMT.053 / MT940 are
 * unambiguous formats, so the hint is null on those paths; CSV is
 * ambiguous (every bank exports its own dialect), so the hint must be
 * carried through or the pipeline must refuse the call.
 *
 * The third assertion is the backstop guard at the public-contract
 * boundary: any caller that bypasses the wizard's server-side validation
 * (other modules, future programmatic surfaces, tests like this one)
 * still cannot land a CSV import without naming the bank.
 */

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
    // There is no ing-csv adapter registered yet; the wiring under test
    // is the contract-level dispatch path. The ImportRun row must be
    // created with source_format='ing-csv' (RunImport creates the row
    // before the pipeline runs) and the unsupported-format error must
    // surface as a single error preview row rather than blowing the
    // whole call up.
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
