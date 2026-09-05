<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Enums\PreviewSectionStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    // Carbon must be frozen alongside the Clock contract: PreviewCache's TTL
    // goes through `Repository::getSeconds()`, which calls Carbon::now() and
    // would otherwise collapse to zero, making the cached preview unreadable.
    $this->frozenNow = CarbonImmutable::parse('2026-05-15 12:00:00');
    Carbon::setTestNow($this->frozenNow);
    CarbonImmutable::setTestNow($this->frozenNow);

    $this->app->instance(Clock::class, new class($this->frozenNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    });

    // Both are singletons an upstream provider boot may already have resolved
    // against the real Clock; the cache must share the frozen one or its
    // 30-minute TTL drifts away from the window under test.
    $this->app->forgetInstance(PreviewCache::class);
    $this->app->forgetInstance(BuildConsolidatedPreviewQuery::class);

    $this->userA = User::query()->create([
        'username' => 'consolidated-a',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->userB = User::query()->create([
        'username' => 'consolidated-b',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function seedConsolidatedRun(
    int $userId,
    string $sourceFormat,
    string $status = 'previewed',
    ?CarbonImmutable $createdAt = null,
): int {
    // Same instant the beforeEach pins, so a default-seeded run always lands
    // inside the 14-day stale window.
    $now = CarbonImmutable::parse('2026-05-15 12:00:00');
    $createdAt ??= $now;

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $userId,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/consolidated-'.bin2hex(random_bytes(4)).'.bin',
        'sha256' => hash('sha256', 'consolidated-'.uniqid('', true)),
        'uploaded_at' => $now,
        'status' => $status,
    ]);

    // `created_at` is what the stale filter reads, and auto-timestamping
    // overwrites it on insert, so it has to be forced after the fact.
    DB::table('import_runs')
        ->where('id', $run->id)
        ->update([
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

    return $run->id;
}

/**
 * @param  list<PreviewRowStatus>  $rowStatuses  One status per fixture row.
 */
function seedConsolidatedPreview(int $importRunId, array $rowStatuses): void
{
    $rows = [];
    foreach ($rowStatuses as $index => $status) {
        $rows[] = new PreviewRowDto(
            rowIndex: $index,
            status: $status,
            accountId: 1,
            postedAt: '2026-05-10',
            counterpartyName: 'Fixture '.$index,
            counterpartyIban: null,
            description: 'fixture-row-'.$index,
            amountMinor: -1000 - $index,
            currency: 'EUR',
            error: null,
        );
    }

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $importRunId,
        new ImportPreviewResult(
            importRunId: $importRunId,
            rows: $rows,
            accountsToName: [],
        ),
        canonical: [],
        enrichments: [],
    );
}

it('builds a consolidated batch with one section per source format', function (): void {
    $camtRun = seedConsolidatedRun($this->userA->id, 'camt053');
    seedConsolidatedPreview($camtRun, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow, PreviewRowStatus::Duplicate]);

    $pdfRunA = seedConsolidatedRun($this->userA->id, 'ics-pdf');
    seedConsolidatedPreview($pdfRunA, [PreviewRowStatus::NewRow]);

    $pdfRunB = seedConsolidatedRun($this->userA->id, 'ics-pdf');
    seedConsolidatedPreview($pdfRunB, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow]);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$camtRun, $pdfRunA, $pdfRunB], $this->userA);

    expect($batch)->toBeInstanceOf(ConsolidatedPreviewBatch::class);
    expect($batch->sections)->toHaveCount(2);

    /** @var array<string, ConsolidatedPreviewSection> $bySource */
    $bySource = [];
    foreach ($batch->sections as $section) {
        $bySource[$section->sourceFormat] = $section;
    }

    expect($bySource)->toHaveKeys(['camt053', 'ics-pdf']);
    expect($bySource['camt053']->importRunIds)->toBe([$camtRun]);
    expect($bySource['camt053']->totalRows)->toBe(2); // 2 NEW rows out of 3
    expect($bySource['camt053']->status)->toBe(PreviewSectionStatus::Ready);

    expect($bySource['ics-pdf']->importRunIds)->toEqualCanonicalizing([$pdfRunA, $pdfRunB]);
    expect($bySource['ics-pdf']->totalRows)->toBe(3); // 1 + 2 NEW rows
    expect($bySource['ics-pdf']->status)->toBe(PreviewSectionStatus::Ready);

    expect($batch->dedupedTotalCount)->toBe(5); // 2 + 1 + 2 NEW
    expect($batch->alreadyImportedCount)->toBe(1); // one DUPLICATE in the CAMT run
})->group('phase-16.1.1');

it('filters stale ImportRuns older than 14 days', function (): void {
    $freshRun = seedConsolidatedRun($this->userA->id, 'camt053');
    seedConsolidatedPreview($freshRun, [PreviewRowStatus::NewRow]);

    $staleRun = seedConsolidatedRun(
        $this->userA->id,
        'mt940',
        status: 'previewed',
        createdAt: $this->frozenNow->subDays(15),
    );
    seedConsolidatedPreview($staleRun, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow, PreviewRowStatus::NewRow]);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$freshRun, $staleRun], $this->userA);

    // Only the fresh run survives the 14-day filter; no MT940 section.
    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sourceFormat)->toBe('camt053');
    expect($batch->sections[0]->importRunIds)->toBe([$freshRun]);
    expect($batch->dedupedTotalCount)->toBe(1);
})->group('phase-16.1.1');

it('filters ImportRuns whose status is confirmed', function (): void {
    $previewedRun = seedConsolidatedRun($this->userA->id, 'camt053');
    seedConsolidatedPreview($previewedRun, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow]);

    $confirmedRun = seedConsolidatedRun(
        $this->userA->id,
        'paypal-csv',
        status: 'confirmed',
    );
    seedConsolidatedPreview($confirmedRun, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow, PreviewRowStatus::NewRow]);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$previewedRun, $confirmedRun], $this->userA);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sourceFormat)->toBe('camt053');
    expect($batch->sections[0]->importRunIds)->toBe([$previewedRun]);
    expect($batch->dedupedTotalCount)->toBe(2);
})->group('phase-16.1.1');

it('respects the user_id boundary — runs owned by another user are never returned', function (): void {
    $aliceRun = seedConsolidatedRun($this->userA->id, 'camt053');
    seedConsolidatedPreview($aliceRun, [PreviewRowStatus::NewRow]);

    $bobRun = seedConsolidatedRun($this->userB->id, 'camt053');
    seedConsolidatedPreview($bobRun, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow, PreviewRowStatus::NewRow]);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$aliceRun, $bobRun], $this->userA);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->importRunIds)->toBe([$aliceRun]);
    expect($batch->dedupedTotalCount)->toBe(1);
})->group('phase-16.1.1');

function seedConsolidatedPreviewRows(int $importRunId, int $rowCount): void
{
    $statuses = array_fill(0, $rowCount, PreviewRowStatus::NewRow);
    seedConsolidatedPreview($importRunId, $statuses);
}

it('defaults to SAMPLE_ROW_LIMIT when no sectionLimitOverrides supplied', function (): void {
    $run = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreviewRows($run, 30);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$run], $this->userA);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sampleRows)->toHaveCount(5);
    expect($batch->sections[0]->totalRows)->toBe(30);
})->group('phase-16.1.2');

it('honors per-section overrides via the sectionLimitOverrides array', function (): void {
    $bankRun = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreviewRows($bankRun, 30);

    $cardRun = seedConsolidatedRun($this->userA->id, 'ics-pdf');
    seedConsolidatedPreviewRows($cardRun, 20);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build(
        [$bankRun, $cardRun],
        $this->userA,
        sectionLimitOverrides: ['asn-csv' => 25],
    );

    $bySource = [];
    foreach ($batch->sections as $section) {
        $bySource[$section->sourceFormat] = $section;
    }

    expect($bySource['asn-csv']->sampleRows)->toHaveCount(25);
    expect($bySource['ics-pdf']->sampleRows)->toHaveCount(5);
})->group('phase-16.1.2');

it('clamps the override naturally when it exceeds the section totalRows', function (): void {
    $run = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreviewRows($run, 10);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build(
        [$run],
        $this->userA,
        sectionLimitOverrides: ['asn-csv' => 999],
    );

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sampleRows)->toHaveCount(10);
})->group('phase-16.1.2');

// The override reaches a SQL slice size, and the ceiling is what keeps that a
// preview: without it, a section drew every committable row of a statement into
// one fragment. Seeded past MAX_SAMPLE_ROW_LIMIT so the clamp and the natural
// "fewer rows than asked for" bound are told apart.
it('clamps an override above MAX_SAMPLE_ROW_LIMIT to the ceiling', function (): void {
    $run = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreviewRows($run, BuildConsolidatedPreviewQuery::MAX_SAMPLE_ROW_LIMIT + 40);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build(
        [$run],
        $this->userA,
        sectionLimitOverrides: ['asn-csv' => 1_000_000],
    );

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sampleRows)->toHaveCount(BuildConsolidatedPreviewQuery::MAX_SAMPLE_ROW_LIMIT);
    expect($batch->sections[0]->totalRows)->toBe(BuildConsolidatedPreviewQuery::MAX_SAMPLE_ROW_LIMIT + 40);
})->group('phase-16.1.2');

it('ignores non-positive overrides and falls back to the default 5-row cap', function (): void {
    $run = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreviewRows($run, 30);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $zeroBatch = $query->build(
        [$run],
        $this->userA,
        sectionLimitOverrides: ['asn-csv' => 0],
    );
    $negativeBatch = $query->build(
        [$run],
        $this->userA,
        sectionLimitOverrides: ['asn-csv' => -5],
    );

    expect($zeroBatch->sections[0]->sampleRows)->toHaveCount(5);
    expect($negativeBatch->sections[0]->sampleRows)->toHaveCount(5);
})->group('phase-16.1.2');

// A failed parse used to arrive as a single error row, counting as neither
// committable nor duplicate — so the section came back `ready` with a total of
// zero and the wizard drew "0 rows · ✓ READY" over a commit button. It is now
// a file-level failure with no rows at all, because the parse stopped and the
// rows it never reached are absent rather than present-and-failed.
function seedFailedParse(int $importRunId, string $reason): void
{
    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $importRunId,
        new ImportPreviewResult(
            importRunId: $importRunId,
            rows: [],
            accountsToName: [],
            fileFailureReason: ImportFailureReason::FileUnreadable,
            fileFailureDetail: $reason,
        ),
        canonical: [],
        enrichments: [],
    );
}

it('reports a failed parse as an error, not as a ready section with no rows', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'camt053');
    seedFailedParse($runId, 'This XML file does not declare an ISO 20022 CAMT.053 namespace.');

    $batch = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA);
    $section = $batch->sections[0];

    expect($section->status)->toBe(PreviewSectionStatus::Error)
        ->and($section->totalRows)->toBe(0);
});

it('carries the parser reason so the screen can say more than "something went wrong"', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'camt053');
    seedFailedParse($runId, 'Re-download the CAMT.053 statement from the ASN portal.');

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA)->sections[0];

    expect($section->error)->toBe('Re-download the CAMT.053 statement from the ASN portal.');
});

// The sample is the reader's only look at what committing writes, and the
// section table has no status column. A failed row shown among the others
// there reads as one more transaction that is about to be imported.
it('keeps failed rows out of the sample, which stands for what committing writes', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreview($runId, [PreviewRowStatus::NewRow, PreviewRowStatus::Error, PreviewRowStatus::NewRow]);

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA)->sections[0];

    expect($section->sampleRows)->toHaveCount(2)
        ->and($section->totalRows)->toBe(2);
    foreach ($section->sampleRows as $row) {
        expect($row->status)->not->toBe(PreviewRowStatus::Error);
    }
});

// A part-read file yields rows before the stop, and ConfirmImport refuses all
// of them: the rows the read never reached are absent rather than failed, so
// nothing would mark the gap. Counting them here offered a commit the wizard
// then could not make, and the refusal took the whole batch down with it.
it('leaves a part-read file out of the section rather than offering rows the commit refuses', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedPartiallyReadFile($runId, 'Row 3: A two digit day could not be found.');

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA)->sections[0];

    expect($section->status)->toBe(PreviewSectionStatus::Error)
        ->and($section->totalRows)->toBe(0)
        ->and($section->importRunIds)->toBe([])
        ->and($section->sampleRows)->toBe([])
        ->and($section->error)->toBe('Row 3: A two digit day could not be found.');
});

// The batch is what one bad file must not take down: the reader dropped both
// of these on the first-run screen, and the clean one still has to import.
it('commits the clean file in a section that also holds a part-read one', function (): void {
    $cleanRun = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreview($cleanRun, [PreviewRowStatus::NewRow, PreviewRowStatus::NewRow]);
    $partRun = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedPartiallyReadFile($partRun, 'Row 3: A two digit day could not be found.');

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)
        ->build([$cleanRun, $partRun], $this->userA)->sections[0];

    expect($section->status)->toBe(PreviewSectionStatus::Ready)
        ->and($section->importRunIds)->toBe([$cleanRun])
        ->and($section->totalRows)->toBe(2)
        ->and($section->error)->toBe('Row 3: A two digit day could not be found.');
});

function seedPartiallyReadFile(int $importRunId, string $reason): void
{
    $rows = [];
    foreach ([0, 1] as $index) {
        $rows[] = new PreviewRowDto(
            rowIndex: $index,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            postedAt: '2026-05-10',
            counterpartyName: 'Fixture '.$index,
            counterpartyIban: null,
            description: 'fixture-row-'.$index,
            amountMinor: -1000 - $index,
            currency: 'EUR',
            error: null,
        );
    }

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $importRunId,
        new ImportPreviewResult(
            importRunId: $importRunId,
            rows: $rows,
            accountsToName: [],
            // FileStoppedShort, not FileUnreadable: every exception naming a
            // format mismatch is raised from a header or preamble, before a
            // row exists, so a preview carrying rows AND FileUnreadable is a
            // shape the pipeline never produces.
            fileFailureReason: ImportFailureReason::FileStoppedShort,
            fileFailureDetail: $reason,
        ),
        canonical: [],
        enrichments: [],
    );
}

it('still reads a genuinely empty statement as empty, not as a failure', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreview($runId, []);

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA)->sections[0];

    expect($section->status)->toBe(PreviewSectionStatus::Empty)
        ->and($section->error)->toBeNull();
});
