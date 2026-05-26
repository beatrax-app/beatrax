<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\ImportRun;

/*
 * Covers BuildConsolidatedPreviewQuery — the read-side projection the
 * FirstImportStep renders as the "review everything before commit"
 * surface. The query receives the list of ImportRun ids stashed by the
 * connector steps and returns a per-source section batch, applying:
 *
 *   1. D-10 stale filter — runs older than 14 days are dropped before
 *      the cache lookup so a forgotten browser tab never replays last
 *      month's preview.
 *   2. D-10 already-confirmed filter — runs whose status is
 *      `confirmed` are dropped so a refresh / back-button never
 *      surfaces an already-committed run.
 *   3. User-id boundary — runs owned by another user are silently
 *      filtered out per the multi-user-readiness rule (T-16.1.1-21).
 *      The query MUST never trust a caller-supplied id list.
 *   4. Per-source grouping — surviving ids are grouped by
 *      source_format and one ConsolidatedPreviewSection is emitted per
 *      group so the wizard can render a section heading per format.
 */

beforeEach(function (): void {
    // Freeze the clock so the 14-day stale window is deterministic.
    $this->frozenNow = CarbonImmutable::parse('2026-05-15 12:00:00');
    $this->app->instance(Clock::class, new class($this->frozenNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    });

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

/**
 * Insert one `import_runs` row owned by the given user. Returns the id.
 * `createdAt` overrides the timestamps column for the stale-filter
 * tests; leave it null to pin to "now".
 */
function seedConsolidatedRun(
    int $userId,
    string $sourceFormat,
    string $status = 'previewed',
    ?CarbonImmutable $createdAt = null,
): int {
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

    // The `created_at` timestamp is the column the stale filter reads
    // (Plan 16.1.1-07 behaviour line). Force it directly because
    // Eloquent overwrites it on insert when the model is auto-timestamping.
    DB::table('import_runs')
        ->where('id', $run->id)
        ->update([
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

    return $run->id;
}

/**
 * Seed a single-row preview into the PreviewCache for the given run.
 * The row's `status` decides whether it counts as a NEW row or a
 * DUPLICATE in the resulting section totals.
 *
 * @param  list<string>  $rowStatuses  One status string per fixture row (e.g. ['new', 'new', 'duplicate']).
 */
function seedConsolidatedPreview(int $importRunId, array $rowStatuses): void
{
    $rows = [];
    foreach ($rowStatuses as $index => $status) {
        $rows[] = new PreviewRowDto(
            rowIndex: $index,
            status: $status,
            accountId: 1,
            bookedAt: '2026-05-10',
            counterpartyName: 'Fixture '.$index,
            counterpartyIban: null,
            description: 'fixture-row-'.$index,
            categoryName: null,
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
    $camtRun = seedConsolidatedRun($this->userA->id, 'asn-camt053');
    seedConsolidatedPreview($camtRun, ['new', 'new', 'duplicate']);

    $pdfRunA = seedConsolidatedRun($this->userA->id, 'ics-pdf');
    seedConsolidatedPreview($pdfRunA, ['new']);

    $pdfRunB = seedConsolidatedRun($this->userA->id, 'ics-pdf');
    seedConsolidatedPreview($pdfRunB, ['new', 'new']);

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

    expect($bySource)->toHaveKeys(['asn-camt053', 'ics-pdf']);
    expect($bySource['asn-camt053']->importRunIds)->toBe([$camtRun]);
    expect($bySource['asn-camt053']->totalRows)->toBe(2); // 2 NEW rows out of 3
    expect($bySource['asn-camt053']->status)->toBe('ready');

    expect($bySource['ics-pdf']->importRunIds)->toEqualCanonicalizing([$pdfRunA, $pdfRunB]);
    expect($bySource['ics-pdf']->totalRows)->toBe(3); // 1 + 2 NEW rows
    expect($bySource['ics-pdf']->status)->toBe('ready');

    expect($batch->dedupedTotalCount)->toBe(5); // 2 + 1 + 2 NEW
    expect($batch->alreadyImportedCount)->toBe(1); // one DUPLICATE in the CAMT run
})->group('phase-16.1.1');

it('filters stale ImportRuns older than 14 days', function (): void {
    $freshRun = seedConsolidatedRun($this->userA->id, 'asn-camt053');
    seedConsolidatedPreview($freshRun, ['new']);

    $staleRun = seedConsolidatedRun(
        $this->userA->id,
        'asn-mt940',
        status: 'previewed',
        createdAt: $this->frozenNow->subDays(15),
    );
    seedConsolidatedPreview($staleRun, ['new', 'new', 'new']);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$freshRun, $staleRun], $this->userA);

    // Only the fresh run survives the 14-day filter; no MT940 section.
    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sourceFormat)->toBe('asn-camt053');
    expect($batch->sections[0]->importRunIds)->toBe([$freshRun]);
    expect($batch->dedupedTotalCount)->toBe(1);
})->group('phase-16.1.1');

it('filters ImportRuns whose status is confirmed', function (): void {
    $previewedRun = seedConsolidatedRun($this->userA->id, 'asn-camt053');
    seedConsolidatedPreview($previewedRun, ['new', 'new']);

    $confirmedRun = seedConsolidatedRun(
        $this->userA->id,
        'paypal-csv',
        status: 'confirmed',
    );
    seedConsolidatedPreview($confirmedRun, ['new', 'new', 'new']);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$previewedRun, $confirmedRun], $this->userA);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->sourceFormat)->toBe('asn-camt053');
    expect($batch->sections[0]->importRunIds)->toBe([$previewedRun]);
    expect($batch->dedupedTotalCount)->toBe(2);
})->group('phase-16.1.1');

it('respects the user_id boundary — runs owned by another user are never returned', function (): void {
    $aliceRun = seedConsolidatedRun($this->userA->id, 'asn-camt053');
    seedConsolidatedPreview($aliceRun, ['new']);

    $bobRun = seedConsolidatedRun($this->userB->id, 'asn-camt053');
    seedConsolidatedPreview($bobRun, ['new', 'new', 'new']);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    // Alice queries with BOTH ids — Bob's run MUST be filtered out
    // before any cache lookup or row count.
    $batch = $query->build([$aliceRun, $bobRun], $this->userA);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->importRunIds)->toBe([$aliceRun]);
    expect($batch->dedupedTotalCount)->toBe(1);
})->group('phase-16.1.1');
