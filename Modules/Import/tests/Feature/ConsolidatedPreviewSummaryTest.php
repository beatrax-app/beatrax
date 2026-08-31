<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    // Carbon must be frozen alongside the Clock contract: PreviewCache's TTL
    // goes through `Repository::getSeconds()`, which calls Carbon::now() and
    // would otherwise collapse to zero, making the cached preview unreadable.
    $frozen = CarbonImmutable::parse('2026-05-15 12:00:00');
    Carbon::setTestNow($frozen);
    CarbonImmutable::setTestNow($frozen);

    $this->app->instance(Clock::class, new class($frozen) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    });

    $this->app->forgetInstance(PreviewCache::class);
    $this->app->forgetInstance(BuildConsolidatedPreviewQuery::class);

    $this->user = User::query()->create([
        'username' => 'summary-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function summaryRun(int $userId, string $sourceFormat): int
{
    $now = CarbonImmutable::parse('2026-05-15 12:00:00');

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $userId,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/summary-'.bin2hex(random_bytes(4)).'.bin',
        'sha256' => hash('sha256', 'summary-'.uniqid('', true)),
        'uploaded_at' => $now,
        'status' => 'previewed',
    ]);

    DB::table('import_runs')
        ->where('id', $run->id)
        ->update([
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

    return $run->id;
}

/**
 * @param  list<array{0: PreviewRowStatus, 1: ?ImportFailureReason}>  $rowSpecs
 */
function summaryPreview(
    int $importRunId,
    array $rowSpecs,
    ?ImportFailureReason $fileFailureReason = null,
    ?string $fileFailureDetail = null,
): void {
    $rows = [];
    foreach ($rowSpecs as $index => [$status, $reason]) {
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
            error: $reason?->label(),
            errorReason: $reason,
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
            fileFailureReason: $fileFailureReason,
            fileFailureDetail: $fileFailureDetail,
        ),
        canonical: [],
        enrichments: [],
    );
}

function summaryForgetSummaries(int ...$importRunIds): void
{
    /** @var Repository $backend */
    $backend = app(Repository::class);
    foreach ($importRunIds as $importRunId) {
        $backend->forget("import.{$importRunId}.preview-summary");
    }
}

/**
 * @param  list<int>  $importRunIds
 * @param  array<string, int>  $overrides
 * @return array<string, mixed>
 */
function summaryBatchArray(array $importRunIds, User $user, array $overrides = []): array
{
    /** @var BuildConsolidatedPreviewQuery $query */
    $query = app(BuildConsolidatedPreviewQuery::class);

    return $query->build($importRunIds, $user, $overrides)->toArray();
}

// The summarised read and the read that walks every row have to answer the
// same thing, because the second is what the first was derived from and what
// still answers a section the reader has expanded.
/**
 * @param  list<int>  $importRunIds
 * @param  array<string, int>  $overrides
 * @return array<string, mixed>
 */
function summaryBothPaths(array $importRunIds, User $user, array $overrides = []): array
{
    $fromSummary = summaryBatchArray($importRunIds, $user, $overrides);

    summaryForgetSummaries(...$importRunIds);
    $fromRows = summaryBatchArray($importRunIds, $user, $overrides);

    expect($fromSummary)->toBe($fromRows);

    return $fromSummary;
}

it('answers a run with no rows at all as empty', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    summaryPreview($run, []);

    $batch = summaryBothPaths([$run], $this->user);

    expect($batch['sections'][0]['status'])->toBe('empty')
        ->and($batch['sections'][0]['totalRows'])->toBe(0)
        ->and($batch['sections'][0]['sampleRows'])->toBe([])
        ->and($batch['sections'][0]['error'])->toBeNull()
        ->and($batch['dedupedTotalCount'])->toBe(0)
        ->and($batch['alreadyImportedCount'])->toBe(0);
});

it('answers a run whose every row failed as an error carrying the first reason that named itself', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    summaryPreview($run, [
        [PreviewRowStatus::Error, null],
        [PreviewRowStatus::Error, ImportFailureReason::UnknownAccount],
        [PreviewRowStatus::Error, ImportFailureReason::RowUnreadable],
    ]);

    $batch = summaryBothPaths([$run], $this->user);

    expect($batch['sections'][0]['status'])->toBe('error')
        ->and($batch['sections'][0]['totalRows'])->toBe(0)
        ->and($batch['sections'][0]['sampleRows'])->toBe([])
        ->and($batch['sections'][0]['error'])->toBe(ImportFailureReason::UnknownAccount->label());
});

it('answers a part-failed run as ready, counting only what committing writes', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    summaryPreview($run, [
        [PreviewRowStatus::Error, ImportFailureReason::RowUnreadable],
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::Duplicate, null],
        [PreviewRowStatus::Enriched, null],
        [PreviewRowStatus::Error, ImportFailureReason::UnknownAccount],
        [PreviewRowStatus::NewRow, null],
    ]);

    $batch = summaryBothPaths([$run], $this->user);
    $section = $batch['sections'][0];

    expect($section['status'])->toBe('ready')
        ->and($section['totalRows'])->toBe(3)
        ->and($batch['alreadyImportedCount'])->toBe(1)
        ->and($section['error'])->toBe(ImportFailureReason::RowUnreadable->label())
        ->and(array_column($section['sampleRows'], 'rowIndex'))->toBe([1, 2, 3, 5]);
});

it('samples the first five of a run that holds more rows than the limit', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    $rows = [];
    for ($i = 0; $i < 12; $i++) {
        $rows[] = [PreviewRowStatus::NewRow, null];
    }
    summaryPreview($run, $rows);

    $batch = summaryBothPaths([$run], $this->user);
    $section = $batch['sections'][0];

    expect($section['totalRows'])->toBe(12)
        ->and($section['sampleRows'])->toHaveCount(BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT)
        ->and(array_column($section['sampleRows'], 'rowIndex'))->toBe([0, 1, 2, 3, 4]);
});

it('samples a run holding exactly the limit without asking for a sixth row', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    $rows = [];
    for ($i = 0; $i < BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT; $i++) {
        $rows[] = [PreviewRowStatus::NewRow, null];
    }
    summaryPreview($run, $rows);

    $batch = summaryBothPaths([$run], $this->user);
    $section = $batch['sections'][0];

    expect($section['totalRows'])->toBe(5)
        ->and(array_column($section['sampleRows'], 'rowIndex'))->toBe([0, 1, 2, 3, 4]);
});

it('reads a section without the run rows once the summary is written', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    summaryPreview($run, [
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::Duplicate, null],
        [PreviewRowStatus::NewRow, null],
    ]);

    /** @var Repository $backend */
    $backend = app(Repository::class);
    $backend->forget("import.{$run}.preview");

    $batch = summaryBatchArray([$run], $this->user);
    $section = $batch['sections'][0];

    expect($section['status'])->toBe('ready')
        ->and($section['totalRows'])->toBe(2)
        ->and($batch['alreadyImportedCount'])->toBe(1)
        ->and(array_column($section['sampleRows'], 'rowIndex'))->toBe([0, 1, 2]);
});

it('reads the rows back when a section is expanded past the stored sample', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    $rows = [];
    for ($i = 0; $i < 40; $i++) {
        $rows[] = [$i % 7 === 0 ? PreviewRowStatus::Error : PreviewRowStatus::NewRow, $i % 7 === 0 ? ImportFailureReason::RowUnreadable : null];
    }
    summaryPreview($run, $rows);

    $batch = summaryBothPaths([$run], $this->user, ['camt053' => 30]);
    $section = $batch['sections'][0];

    expect($section['sampleRows'])->toHaveCount(30)
        ->and($section['totalRows'])->toBe(34)
        ->and(array_column($section['sampleRows'], 'rowIndex'))
        ->not->toContain(0)
        ->not->toContain(7);
});

it('keeps several runs of one format in the order they were given', function (): void {
    $first = summaryRun($this->user->id, 'camt053');
    $second = summaryRun($this->user->id, 'camt053');

    summaryPreview($first, [
        [PreviewRowStatus::Error, ImportFailureReason::AppLocked],
        [PreviewRowStatus::NewRow, null],
    ]);
    summaryPreview($second, [
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::NewRow, null],
    ]);

    $batch = summaryBothPaths([$first, $second], $this->user);
    $section = $batch['sections'][0];

    expect($section['status'])->toBe('ready')
        ->and($section['totalRows'])->toBe(6)
        ->and($section['error'])->toBe(ImportFailureReason::AppLocked->label())
        ->and($section['sampleRows'])->toHaveCount(5)
        ->and(array_column($section['sampleRows'], 'description'))
        ->toBe(['fixture-row-1', 'fixture-row-0', 'fixture-row-1', 'fixture-row-2', 'fixture-row-3']);
});

it('answers a file that failed before it yielded a row as an error with the parser reason', function (): void {
    $run = summaryRun($this->user->id, 'ics-pdf');
    summaryPreview($run, [], ImportFailureReason::FileUnreadable, 'Expected a card statement, found a bank export.');

    $batch = summaryBothPaths([$run], $this->user);
    $section = $batch['sections'][0];

    expect($section['status'])->toBe('error')
        ->and($section['totalRows'])->toBe(0)
        ->and($section['error'])->toBe('Expected a card statement, found a bank export.');
});

it('shows a renamed row in the sample the consolidated screen reads', function (): void {
    $run = summaryRun($this->user->id, 'camt053');
    summaryPreview($run, [
        [PreviewRowStatus::NewRow, null],
        [PreviewRowStatus::NewRow, null],
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    expect($cache->applyAliasInPlace($run, 1, 'Albert Heijn'))->toBeTrue();

    $batch = summaryBothPaths([$run], $this->user);

    expect(array_column($batch['sections'][0]['sampleRows'], 'aliasFriendlyName'))
        ->toBe([null, 'Albert Heijn']);
});
