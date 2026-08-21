<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\ImportRun;

// A remote fetch has no local file, so the caller-supplied idempotency key
// stands in for runFromUpload()'s file-SHA256 dedup: one key reuses one
// ImportRun row across repeated "Sync now" clicks in the same fetch window.

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
    // The clock is moved between the two calls; only uploaded_at bookkeeping
    // reads it, so one key must still converge on one row.
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
