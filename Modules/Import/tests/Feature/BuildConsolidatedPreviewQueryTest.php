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
    // Freeze BOTH the injected Clock contract and the global Carbon
    // "now" so the 14-day stale window is deterministic AND the
    // PreviewCache's `Repository::getSeconds()` TTL conversion (which
    // calls Carbon::now() under the hood) does not collapse to zero
    // — leaving the cached preview unreadable in subsequent reads.
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

    // PreviewCache + BuildConsolidatedPreviewQuery are singletons that
    // captured the previous Clock binding when first resolved by an
    // upstream service-provider boot. Drop both so a fresh resolve
    // picks up the frozen Clock above. The query is the one under
    // test; the cache it reads from must share the same Clock or its
    // 30-minute TTL drifts away from the frozen window.
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
    // Mirror the frozen-clock instant the beforeEach pinned via
    // Carbon::setTestNow so every default-seeded run lands INSIDE the
    // 14-day stale window unless the caller explicitly overrides
    // `$createdAt` to push the row past the cutoff.
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
    $camtRun = seedConsolidatedRun($this->userA->id, 'camt053');
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

    expect($bySource)->toHaveKeys(['camt053', 'ics-pdf']);
    expect($bySource['camt053']->importRunIds)->toBe([$camtRun]);
    expect($bySource['camt053']->totalRows)->toBe(2); // 2 NEW rows out of 3
    expect($bySource['camt053']->status)->toBe('ready');

    expect($bySource['ics-pdf']->importRunIds)->toEqualCanonicalizing([$pdfRunA, $pdfRunB]);
    expect($bySource['ics-pdf']->totalRows)->toBe(3); // 1 + 2 NEW rows
    expect($bySource['ics-pdf']->status)->toBe('ready');

    expect($batch->dedupedTotalCount)->toBe(5); // 2 + 1 + 2 NEW
    expect($batch->alreadyImportedCount)->toBe(1); // one DUPLICATE in the CAMT run
})->group('phase-16.1.1');

it('filters stale ImportRuns older than 14 days', function (): void {
    $freshRun = seedConsolidatedRun($this->userA->id, 'camt053');
    seedConsolidatedPreview($freshRun, ['new']);

    $staleRun = seedConsolidatedRun(
        $this->userA->id,
        'mt940',
        status: 'previewed',
        createdAt: $this->frozenNow->subDays(15),
    );
    seedConsolidatedPreview($staleRun, ['new', 'new', 'new']);

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
    expect($batch->sections[0]->sourceFormat)->toBe('camt053');
    expect($batch->sections[0]->importRunIds)->toBe([$previewedRun]);
    expect($batch->dedupedTotalCount)->toBe(2);
})->group('phase-16.1.1');

it('respects the user_id boundary — runs owned by another user are never returned', function (): void {
    $aliceRun = seedConsolidatedRun($this->userA->id, 'camt053');
    seedConsolidatedPreview($aliceRun, ['new']);

    $bobRun = seedConsolidatedRun($this->userB->id, 'camt053');
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

/*
 * Per-section row-cap override coverage.
 *
 * `BuildConsolidatedPreviewQuery::build()` accepts a third named
 * argument `array<string, int> $sectionLimitOverrides`. When present,
 * a section whose `source_format` is keyed in the map renders up to
 * the override row count instead of the default `SAMPLE_ROW_LIMIT`
 * (5). Sections absent from the map keep the 5-row default. Non-
 * positive overrides (≤ 0) are silently ignored as a server-side
 * clamp against a tampered wire-click payload.
 */

/**
 * Repeat a single PreviewRowDto fixture `$rowCount` times and feed
 * them into the PreviewCache for the given ImportRun. Mirrors the
 * existing `seedConsolidatedPreview()` helper but lets the caller
 * pick an arbitrary row count without listing each status literal.
 */
function seedConsolidatedPreviewRows(int $importRunId, int $rowCount): void
{
    $statuses = array_fill(0, $rowCount, 'new');
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
    expect($bySource['ics-pdf']->sampleRows)->toHaveCount(5); // default unchanged
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

/*
 * A parse that fails contributes exactly one row, an error row carrying the
 * reason. It counts as neither committable nor duplicate, so the section used
 * to come back `ready` with a total of zero — and the wizard drew "0 rows ·
 * ✓ READY" with a commit button beneath it.
 *
 * Measured on a device: an ASN CSV uploaded against the wizard's CAMT.053
 * default. The parser refused it correctly and said exactly why — "This XML
 * file does not declare an ISO 20022 CAMT.053 namespace" — and the screen
 * showed a tick.
 */

function seedFailedParse(int $importRunId, string $reason): void
{
    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $importRunId,
        new ImportPreviewResult(
            importRunId: $importRunId,
            rows: [new PreviewRowDto(
                rowIndex: 0,
                status: 'error',
                accountId: null,
                bookedAt: null,
                counterpartyName: null,
                counterpartyIban: null,
                description: null,
                categoryName: null,
                amountMinor: null,
                currency: null,
                error: $reason,
            )],
            accountsToName: [],
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

    expect($section->status)->toBe('error')
        ->and($section->totalRows)->toBe(0);
});

it('carries the parser reason so the screen can say more than "something went wrong"', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'camt053');
    seedFailedParse($runId, 'Re-download the CAMT.053 statement from the ASN portal.');

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA)->sections[0];

    expect($section->error)->toBe('Re-download the CAMT.053 statement from the ASN portal.');
});

it('still reads a genuinely empty statement as empty, not as a failure', function (): void {
    $runId = seedConsolidatedRun($this->userA->id, 'asn-csv');
    seedConsolidatedPreview($runId, []);

    $section = $this->app->make(BuildConsolidatedPreviewQuery::class)->build([$runId], $this->userA)->sections[0];

    expect($section->status)->toBe('empty')
        ->and($section->error)->toBeNull();
});
