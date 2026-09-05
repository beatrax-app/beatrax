<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Import\Internal\Enums\ImportIssueKind;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
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
            status: PreviewRowStatus::Duplicate,
            accountId: 1,
            postedAt: '01-08-2026',
            counterpartyName: 'Albert Heijn',
            counterpartyIban: null,
            description: 'groceries',
            amountMinor: -1234,
            currency: 'EUR',
            error: null,
        ),
        new PreviewRowDto(
            rowIndex: 4,
            status: PreviewRowStatus::Error,
            accountId: 1,
            postedAt: null,
            counterpartyName: null,
            counterpartyIban: null,
            description: null,
            amountMinor: null,
            currency: 'EUR',
            error: 'This row could not be read.',
            errorReason: ImportFailureReason::RowUnreadable,
            errorDetail: 'The date in row 5 was written 31-13-2026.',
        ),
    ];

    app(PreviewCache::class)->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: $rows,
            accountsToName: [],
        ),
        canonical: [],
        enrichments: [],
    );

    app(ConfirmsImports::class)($run->id, $this->fixtureUser);

    Livewire::test(ImportResults::class, ['id' => $run->id])
        ->assertSee('Row 5: This row could not be read.')
        ->assertSee('The date in row 5 was written 31-13-2026.')
        ->assertSee('Row 1 was already in your ledger.');
});

// No confirm can produce this run any more: a file whose read stopped short is
// refused outright now. Runs confirmed before that refusal existed still carry
// the issue in row_issues, so the screen is still the one the reader comes back
// to next month — which is why the stored shape is built here by hand.
it('still explains its truncation on a run confirmed before part-read files were refused', function (): void {
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/truncated-fixture.csv',
        'sha256' => hash('sha256', 'truncated-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'confirmed_at' => CarbonImmutable::parse('2026-08-01 09:01:00'),
        'inserted_count' => 3,
        'error_count' => 1,
        'row_issues' => [
            [
                'kind' => ImportIssueKind::FileError->value,
                'row' => 3,
                'reason' => ImportFailureReason::FileStoppedShort->value,
                'detail' => null,
            ],
        ],
        'status' => 'confirmed',
    ]);

    Livewire::test(ImportResults::class, ['id' => $run->id])
        ->assertSee('The file could not be read past row 4.')
        ->assertSee('Nothing after that row was imported.');
});

// The preview screen and the results screen each answered "why did this row
// fail?" for a row that recorded no reason, and they answered differently:
// "This row could not be read." against "No reason was recorded." — the second
// describing the record rather than the row.
it('gives a row that failed without a recorded reason the same sentence the preview gave it', function (): void {
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/reasonless-fixture.csv',
        'sha256' => hash('sha256', 'reasonless-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'status' => 'previewed',
    ]);

    app(PreviewCache::class)->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: [
                // A run of nothing but failed rows cannot be confirmed at all,
                // so the reasonless row needs a landed one beside it for the
                // results screen to exist to be read.
                new PreviewRowDto(
                    rowIndex: 0,
                    status: PreviewRowStatus::Duplicate,
                    accountId: 1,
                    postedAt: '01-08-2026',
                    counterpartyName: 'Albert Heijn',
                    counterpartyIban: null,
                    description: 'groceries',
                    amountMinor: -1234,
                    currency: 'EUR',
                    error: null,
                ),
                new PreviewRowDto(
                    rowIndex: 4,
                    status: PreviewRowStatus::Error,
                    accountId: 1,
                    postedAt: null,
                    counterpartyName: null,
                    counterpartyIban: null,
                    description: null,
                    amountMinor: null,
                    currency: 'EUR',
                    error: null,
                    errorReason: null,
                    errorDetail: null,
                ),
            ],
            accountsToName: [],
            fileFailureReason: null,
            fileFailureDetail: null,
            fileFailureRowIndex: null,
        ),
        canonical: [],
        enrichments: [],
    );

    app(ConfirmsImports::class)($run->id, $this->fixtureUser);

    Livewire::test(ImportResults::class, ['id' => $run->id])
        ->assertSee('Row 5: '.ImportFailureReason::RowUnreadable->label())
        ->assertDontSee('No reason was recorded.');
});
