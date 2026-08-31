<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

// CAMT.053 and MT940 are unambiguous, so the hint is null on those paths. Every
// bank exports its own CSV dialect, so a CSV import without a hint has to be
// refused at the contract boundary, not only by the wizard's own validation.

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
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

it('parses an ING CSV through its preset without a format hint', function (): void {
    // A preset names its dialect in its own format id, so it needs no hint and
    // must not be refused for arriving without one.
    Account::query()->updateOrCreate(
        ['iban' => 'NL91ABNA0417164300'],
        [
            'user_id' => $this->fixtureUser->id,
            'name' => 'ING Fixture Account',
            'slug' => 'ing-fixture',
            'kind' => 'bank',
            'default_currency' => 'EUR',
        ],
    );

    $fixture = __DIR__.'/../../../Ingestion/tests/fixtures/csv/ing-nl-sample.csv';

    $preview = $this->importer->runFromUpload(
        $fixture,
        CsvPresetRegistry::ING_NL,
        $this->fixtureUser,
        'ing-nl-sample.csv',
        null,
    );

    expect($preview->importRunId)->toBeGreaterThan(0);
    expect($preview->rows)->not->toBeEmpty();

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($preview->importRunId);
    expect($run->source_format)->toBe(CsvPresetRegistry::ING_NL);
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
