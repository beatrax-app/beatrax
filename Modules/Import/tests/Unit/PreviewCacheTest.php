<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Exceptions\PreviewCacheCorruptedException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Pipeline\PreviewKeys;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;

beforeEach(function (): void {
    /** @var Repository $cache */
    $cache = $this->app->make(Repository::class);
    $this->cacheBackend = $cache;

    $this->clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-02-15 12:00:00');
        }
    };

    // Carbon must be frozen alongside the Clock contract: PreviewCache's TTL
    // goes through `Repository::getSeconds()`, which calls Carbon::now() and
    // would otherwise collapse to zero, making the cached preview unreadable.
    Carbon::setTestNow($this->clock->now());
    CarbonImmutable::setTestNow($this->clock->now());

    $this->cache = new PreviewCache($this->cacheBackend, $this->clock);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function previewRow(int $index, PreviewRowStatus $status = PreviewRowStatus::NewRow): PreviewRowDto
{
    return new PreviewRowDto(
        rowIndex: $index,
        status: $status,
        accountId: 1,
        bookedAt: '2026-02-01',
        counterpartyName: 'Row '.$index,
        counterpartyIban: null,
        description: null,
        categoryName: null,
        amountMinor: -100 - $index,
        currency: 'EUR',
        error: null,
        errorReason: $status === PreviewRowStatus::Error ? ImportFailureReason::RowUnreadable : null,
    );
}

/** @param list<PreviewRowDto> $rows */
function storePreview(int $importRunId, array $rows, ?ImportFailureReason $fileFailure = null): void
{
    test()->cache->put($importRunId, new ImportPreviewResult(
        importRunId: $importRunId,
        rows: $rows,
        accountsToName: [],
        fileFailureReason: $fileFailure,
    ), []);
}

it('returns null from getCanonical when the key has never been set', function (): void {
    expect($this->cache->getCanonical(123))->toBeNull();
})->group('phase-2');

it('returns null from getEnrichments when the key has never been set', function (): void {
    expect($this->cache->getEnrichments(123))->toBeNull();
})->group('phase-2');

it('returns null from getPreview when the key has never been set', function (): void {
    expect($this->cache->getPreview(123))->toBeNull();
})->group('phase-2');

it('throws PreviewCacheCorruptedException on a non-string canonical payload', function (): void {
    // Stands in for a cache backend that rotated the value type under load.
    storePreview(123, [previewRow(0)]);
    $this->cacheBackend->put(PreviewKeys::canonicalChunk(123, 0), ['not', 'a', 'string'], 600);

    expect(fn () => $this->cache->getCanonical(123))
        ->toThrow(PreviewCacheCorruptedException::class, 'import.123.canonical.0');
})->group('phase-2');

it('throws PreviewCacheCorruptedException on a non-JSON canonical payload', function (): void {
    storePreview(123, [previewRow(0)]);
    $this->cacheBackend->put(PreviewKeys::canonicalChunk(123, 0), '{not valid json', 600);

    expect(fn () => $this->cache->getCanonical(123))
        ->toThrow(PreviewCacheCorruptedException::class, 'import.123.canonical.0');
})->group('phase-2');

it('throws PreviewCacheCorruptedException on a non-array head payload', function (): void {
    $this->cacheBackend->put(PreviewKeys::head(123), 'unexpected scalar', 600);

    expect(fn () => $this->cache->getPreview(123))
        ->toThrow(PreviewCacheCorruptedException::class, 'import.123.preview.head');
})->group('phase-2');

// The cache outlives a deploy by its TTL, and the build before this one wrote a
// whole result array under `import.N.preview`. Read as a head that shape throws
// on the screen, so the key moved and the old entry is simply not found —
// "expired, re-upload" is a state the wizard already handles.
it('reads a preview written by the previous build as one that is no longer there', function (): void {
    $this->cacheBackend->put('import.123.preview', [
        'importRunId' => 123,
        'rows' => [['rowIndex' => 0, 'status' => 'error']],
        'accountsToName' => [],
        'enrichedCount' => 0,
        'fileFailureReason' => 'file_unreadable',
    ], 600);

    expect($this->cache->getPreview(123))->toBeNull()
        ->and($this->cache->head(123))->toBeNull()
        ->and($this->cache->getCanonical(123))->toBeNull();
})->group('phase-2');

it('writes the same wire strings back into the cache, so the round trip is unchanged', function (): void {
    storePreview(456, [previewRow(0, PreviewRowStatus::Error)], ImportFailureReason::FileUnreadable);

    $head = $this->cacheBackend->get(PreviewKeys::head(456));
    $rows = $this->cacheBackend->get(PreviewKeys::rowChunk(456, 0));

    expect($head)->toBeArray()
        ->and($head['fileFailureReason'] ?? null)->toBe('file_unreadable')
        ->and($rows[0]['status'] ?? null)->toBe('error')
        ->and($rows[0]['errorReason'] ?? null)->toBe('row_unreadable');
})->group('phase-2');

it('hydrates the enums back off the wire strings', function (): void {
    storePreview(456, [previewRow(0, PreviewRowStatus::Error)], ImportFailureReason::FileUnreadable);

    $preview = $this->cache->getPreview(456);
    $row = ($preview?->rows ?? [])[0] ?? null;

    expect($preview?->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable)
        ->and($row?->status)->toBe(PreviewRowStatus::Error)
        ->and($row?->errorReason)->toBe(ImportFailureReason::RowUnreadable);
})->group('phase-2');

// The whole point of the chunking: a run larger than one chunk is stored as
// several, and reading a page reads only the chunks that page falls in.
it('splits a run across chunks and pages back through them', function (): void {
    $rows = [];
    for ($i = 0; $i < PreviewKeys::CHUNK_ROWS * 2 + 7; $i++) {
        $rows[] = previewRow($i);
    }
    storePreview(789, $rows);

    $head = $this->cache->head(789);

    expect($head?->rowChunkCount)->toBe(3)
        ->and($head?->rowCount)->toBe(PreviewKeys::CHUNK_ROWS * 2 + 7);

    $firstPage = $this->cache->rows(789, 0, 10);
    $acrossBoundary = $this->cache->rows(789, PreviewKeys::CHUNK_ROWS - 2, 4);
    $tail = $this->cache->rows(789, PreviewKeys::CHUNK_ROWS * 2, 100);

    expect(array_map(static fn (PreviewRowDto $r): int => $r->rowIndex, $firstPage))
        ->toBe(range(0, 9))
        ->and(array_map(static fn (PreviewRowDto $r): int => $r->rowIndex, $acrossBoundary))
        ->toBe(range(PreviewKeys::CHUNK_ROWS - 2, PreviewKeys::CHUNK_ROWS + 1))
        ->and($tail)->toHaveCount(7);
})->group('phase-2');

// A result carries a window, not the run. Anything that would count its rows
// to answer a question about the run has to ask rowsAreComplete() first.
it('says so when a result holds a window rather than the whole run', function (): void {
    $rows = [];
    for ($i = 0; $i < PreviewCache::RESULT_ROW_WINDOW + 20; $i++) {
        $rows[] = previewRow($i, $i % 10 === 0 ? PreviewRowStatus::Error : PreviewRowStatus::NewRow);
    }
    storePreview(790, $rows);

    $preview = $this->cache->getPreview(790);

    expect($preview?->rows)->toHaveCount(PreviewCache::RESULT_ROW_WINDOW)
        ->and($preview?->rowsAreComplete())->toBeFalse()
        ->and($preview?->totalRows())->toBe(PreviewCache::RESULT_ROW_WINDOW + 20)
        ->and($preview?->errorRows())->toBe(52)
        ->and($preview?->importableRows())->toBe(PreviewCache::RESULT_ROW_WINDOW + 20 - 52);
})->group('phase-2');

it('drops every chunk of a run when the run is forgotten', function (): void {
    $rows = [];
    for ($i = 0; $i < PreviewKeys::CHUNK_ROWS + 1; $i++) {
        $rows[] = previewRow($i);
    }
    storePreview(791, $rows);

    $this->cache->forget(791);

    expect($this->cacheBackend->get(PreviewKeys::head(791)))->toBeNull()
        ->and($this->cacheBackend->get(PreviewKeys::rowChunk(791, 0)))->toBeNull()
        ->and($this->cacheBackend->get(PreviewKeys::rowChunk(791, 1)))->toBeNull()
        ->and($this->cacheBackend->get(PreviewKeys::canonicalChunk(791, 0)))->toBeNull();
})->group('phase-2');
