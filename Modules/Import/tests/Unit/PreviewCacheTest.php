<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Exceptions\PreviewCacheCorruptedException;
use Modules\Import\Internal\Pipeline\PreviewCache;
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
    $this->cacheBackend->put('import.123.canonical', ['not', 'a', 'string'], 600);

    expect(fn () => $this->cache->getCanonical(123))
        ->toThrow(PreviewCacheCorruptedException::class, 'import.123.canonical');
})->group('phase-2');

it('throws PreviewCacheCorruptedException on a non-JSON canonical payload', function (): void {
    $this->cacheBackend->put('import.123.canonical', '{not valid json', 600);

    expect(fn () => $this->cache->getCanonical(123))
        ->toThrow(PreviewCacheCorruptedException::class, 'import.123.canonical');
})->group('phase-2');

it('throws PreviewCacheCorruptedException on a non-array preview payload', function (): void {
    $this->cacheBackend->put('import.123.preview', 'unexpected scalar', 600);

    expect(fn () => $this->cache->getPreview(123))
        ->toThrow(PreviewCacheCorruptedException::class, 'import.123.preview');
})->group('phase-2');

it('hydrates a preview cached before status and the failure reasons were typed', function (): void {
    // The cache outlives a deploy by its TTL, so a payload written in the old
    // all-strings shape is still read back after the properties became enums.
    $this->cacheBackend->put('import.123.preview', [
        'importRunId' => 123,
        'rows' => [[
            'rowIndex' => 0,
            'status' => 'error',
            'accountId' => null,
            'bookedAt' => null,
            'counterpartyName' => null,
            'counterpartyIban' => null,
            'description' => null,
            'categoryName' => null,
            'amountMinor' => null,
            'currency' => null,
            'error' => null,
            'diff' => null,
            'paymentType' => null,
            'aliasFriendlyName' => null,
            'errorReason' => 'row_unreadable',
            'errorDetail' => null,
        ]],
        'accountsToName' => [],
        'enrichedCount' => 0,
        'fileFailureReason' => 'file_unreadable',
        'fileFailureDetail' => null,
        'fileFailureRowIndex' => null,
    ], 600);

    $preview = $this->cache->getPreview(123);
    $row = ($preview?->rows ?? [])[0] ?? null;

    expect($preview?->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable)
        ->and($row?->status)->toBe(PreviewRowStatus::Error)
        ->and($row?->errorReason)->toBe(ImportFailureReason::RowUnreadable);
})->group('phase-2');

it('writes the same wire strings back into the cache, so the round trip is unchanged', function (): void {
    $this->cache->put(456, new ImportPreviewResult(
        importRunId: 456,
        rows: [new PreviewRowDto(
            rowIndex: 0,
            status: PreviewRowStatus::Error,
            accountId: null,
            bookedAt: null,
            counterpartyName: null,
            counterpartyIban: null,
            description: null,
            categoryName: null,
            amountMinor: null,
            currency: null,
            error: null,
            errorReason: ImportFailureReason::RowUnreadable,
        )],
        accountsToName: [],
        fileFailureReason: ImportFailureReason::FileUnreadable,
    ), []);

    $raw = $this->cacheBackend->get('import.456.preview');

    expect($raw)->toBeArray()
        ->and($raw['fileFailureReason'] ?? null)->toBe('file_unreadable')
        ->and($raw['rows'][0]['status'] ?? null)->toBe('error')
        ->and($raw['rows'][0]['errorReason'] ?? null)->toBe('row_unreadable');
})->group('phase-2');
