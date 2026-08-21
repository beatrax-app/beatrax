<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Exceptions\PreviewCacheCorruptedException;
use Modules\Import\Internal\Pipeline\PreviewCache;

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

    $this->cache = new PreviewCache($this->cacheBackend, $this->clock);
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
