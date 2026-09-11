<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Dto\PreviewHead;
use Modules\Import\Internal\Exceptions\PreviewCacheCorruptedException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Pipeline\PreviewKeys;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

// A preview is a head plus chunks under separate cache keys, and nothing keeps
// them on one expiry: applyAliasInPlace re-stamps the head and one row chunk
// with a fresh TTL and leaves the canonical chunks on the original. In that gap
// the head is there and the chunks are not, which is a preview that has expired.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
function expiredChunkReader(): User
{
    return User::query()->create([
        'username' => 'expired-chunk-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function expiredChunkRun(User $user): ImportRun
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/expired-chunk.csv',
        'sha256' => str_pad(bin2hex(random_bytes(8)), 64, 'b'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    return $run;
}

// Counts only: the head says a canonical chunk exists, and the key holding it
// is the one deliberately left unwritten.
function expiredChunkHead(int $importRunId, int $canonicalChunks = 1, int $enrichmentChunks = 0): void
{
    /** @var Repository $cache */
    $cache = app(Repository::class);

    $cache->put(PreviewKeys::head($importRunId), new PreviewHead(
        importRunId: $importRunId,
        accountsToName: [],
        rowCount: 1,
        committableCount: 1,
        duplicateCount: 0,
        errorCount: 0,
        enrichedCount: 0,
        sampleRows: [],
        sampleComplete: true,
        rowIssues: [],
        rowChunkCount: 1,
        canonicalChunkCount: $canonicalChunks,
        enrichmentChunkCount: $enrichmentChunks,
    )->toArray(), 600);
}

it('reads a canonical chunk that is gone as an expired preview', function (): void {
    $run = expiredChunkRun(expiredChunkReader());
    expiredChunkHead((int) $run->id);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);

    expect(static fn (): mixed => $cache->getCanonical((int) $run->id))
        ->toThrow(PreviewExpiredException::class);
});

it('reads an enrichment chunk that is gone as an expired preview', function (): void {
    $run = expiredChunkRun(expiredChunkReader());
    expiredChunkHead((int) $run->id, canonicalChunks: 0, enrichmentChunks: 1);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);

    expect($cache->getEnrichments((int) $run->id))->toBeNull();
});

// The other half of the distinction, and the reason this is not simply a
// swallow: a payload that IS there and will not decode is a cache regression,
// and telling the reader it expired names a cause they can disprove by waiting.
it('still says corruption for a chunk that is there and will not decode', function (): void {
    $run = expiredChunkRun(expiredChunkReader());
    expiredChunkHead((int) $run->id);

    /** @var Repository $cache */
    $repository = app(Repository::class);
    $repository->put(PreviewKeys::canonicalChunk((int) $run->id, 0), ['not', 'a', 'json', 'string'], 600);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);

    expect(static fn (): mixed => $cache->getCanonical((int) $run->id))
        ->toThrow(PreviewCacheCorruptedException::class);
});
