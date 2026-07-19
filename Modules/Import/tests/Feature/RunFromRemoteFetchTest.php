<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\ImportRun;

/*
 * Proves the ONLY legal cross-module entry point a remote-fetch caller
 * (e.g. Modules\OpenBanking's Enable Banking adapter, Wave 2) has into the
 * import pipeline: a Generator<SourceTransactionDto>-driven preview with
 * no local file.
 *
 * The idempotency key substitutes runFromUpload()'s file-SHA256 dedup
 * layer (RESEARCH.md Pitfall 1) — same key means the SAME ImportRun row
 * is reused across repeated "Sync now" clicks within the same fetch
 * window; a distinct fetch window (distinct key) creates a new row. The
 * key itself must never be derived from wall-clock time.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

/**
 * @return Generator<int, SourceTransactionDto>
 */
function fixtureRemoteFetchRows(): Generator
{
    yield new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse('2026-06-01'),
        postedAt: CarbonImmutable::parse('2026-06-01'),
        valueDate: CarbonImmutable::parse('2026-06-01'),
        ownIban: 'NL57ASNB0123456789',
        counterpartyIban: 'NL00BANK0000000001',
        counterpartyName: 'Acme Groceries',
        currency: 'EUR',
        amountMinor: -1234,
        sourceRef: null,
        description: 'Weekly groceries',
        rawPayload: ['enable_banking' => ['transaction_id' => 'tx-1']],
        sourceRowIndex: 0,
    );
}

it('reuses one ImportRun row when the same idempotency key is fetched twice', function (): void {
    $key = hash('sha256', 'open-banking:test-institution:1:2026-06-01:2026-06-30');

    $first = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $key);
    $second = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $key);

    expect($first->importRunId)->toBe($second->importRunId);
    expect(ImportRun::query()->where('sha256', $key)->count())->toBe(1);
});

it('creates a distinct ImportRun row for a different idempotency key', function (): void {
    $keyA = hash('sha256', 'open-banking:test-institution:1:2026-06-01:2026-06-30');
    $keyB = hash('sha256', 'open-banking:test-institution:1:2026-07-01:2026-07-31');

    $a = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $keyA);
    $b = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $keyB);

    expect($a->importRunId)->not->toBe($b->importRunId);
    expect(ImportRun::query()->whereIn('sha256', [$keyA, $keyB])->count())->toBe(2);
});

it('never derives the idempotency dedup key from wall-clock time', function (): void {
    // Two calls issued "at different moments" (Clock is not consulted for
    // the key itself, only for uploaded_at bookkeeping) with the SAME
    // caller-supplied key must still converge on one row — proves the key
    // itself, not any internal timestamp, drives the dedup.
    $key = hash('sha256', 'open-banking:test-institution:1:2026-06-01:2026-06-30');

    $first = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $key);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    $second = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $key);
    CarbonImmutable::setTestNow();

    expect($first->importRunId)->toBe($second->importRunId);
});

it('caches the preview result exactly like the upload path does', function (): void {
    $key = hash('sha256', 'open-banking:test-institution:1:2026-06-01:2026-06-30');

    $result = $this->importer->runFromRemoteFetch(fixtureRemoteFetchRows(), 'enable-banking', $this->fixtureUser, $key);

    /** @var PreviewCache $cache */
    $cache = $this->app->make(PreviewCache::class);
    $cached = $cache->getPreview($result->importRunId);

    expect($cached)->not->toBeNull();
    expect($cached->importRunId)->toBe($result->importRunId);
    expect($cached->rows)->toHaveCount(1);

    $canonical = $cache->getCanonical($result->importRunId);
    expect($canonical)->not->toBeNull();
});
