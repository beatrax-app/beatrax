<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// "Show errors (1)" opened onto one sentence defining the word "error". The
// count promises a list, so the run has to carry enough to produce one: which
// row, and what went wrong with it. The preview cache that knew is dropped a
// few lines after the confirm commits, so it is copied onto the run first.
it('lists the rows it skipped instead of defining the word for them', function (): void {
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/results-fixture.csv',
        'sha256' => hash('sha256', 'results-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'status' => 'previewed',
    ]);

    $rows = [
        new PreviewRowDto(
            rowIndex: 0,
            status: 'duplicate',
            accountId: 1,
            bookedAt: '01-08-2026',
            counterpartyName: 'Albert Heijn',
            counterpartyIban: null,
            description: 'groceries',
            categoryName: null,
            amountMinor: -1234,
            currency: 'EUR',
            error: null,
        ),
        new PreviewRowDto(
            rowIndex: 4,
            status: 'error',
            accountId: 1,
            bookedAt: null,
            counterpartyName: null,
            counterpartyIban: null,
            description: null,
            categoryName: null,
            amountMinor: null,
            currency: 'EUR',
            error: 'This row could not be read.',
            errorReason: 'row_unreadable',
            errorDetail: 'The date in row 5 was written 31-13-2026.',
        ),
    ];

    app(PreviewCache::class)->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: $rows,
            accountsToName: [],
            fileFailureReason: 'file_unreadable',
            fileFailureDetail: 'Expected 19 or 20 columns, got 5.',
            fileFailureRowIndex: null,
        ),
        canonical: [],
        enrichments: [],
    );

    app(ConfirmsImports::class)($run->id, $this->fixtureUser);

    Livewire::test(ImportResults::class, ['id' => $run->id])
        ->assertSee('Row 5: This row could not be read.')
        ->assertSee('The date in row 5 was written 31-13-2026.')
        ->assertSee('The file could not be read at all.')
        ->assertSee('The reader reported: Expected 19 or 20 columns, got 5.')
        ->assertSee('Row 1 was already in your ledger.');
});

it('names the truncation on the results screen after a part-read file is confirmed', function (): void {
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-partial-failure.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-partial-failure.csv',
        BankCsvFormatHint::Asn,
    );

    app(ConfirmsImports::class)($preview->importRunId, $this->fixtureUser);

    $run = ImportRun::query()->findOrFail($preview->importRunId);
    expect($run->inserted_count)->toBe(3);
    expect($run->error_count)->toBe(1);

    // The preview knew reading stopped at the fourth row and that three were
    // read. The results screen is the one the reader comes back to next month,
    // so it is the one that has to still know it.
    Livewire::test(ImportResults::class, ['id' => $preview->importRunId])
        ->assertSee('The file could not be read past row 4.')
        ->assertSee('Nothing after that row was imported.');
});
