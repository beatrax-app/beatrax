<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Illuminate\Contracts\Cache\Repository;
use JsonException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Exceptions\PreviewCacheCorruptedException;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Holds the parsed canonical batch, the pending-enrichments list, and
 * the preview payload between the upload (preview) click and the
 * confirm click. 30-minute TTL.
 *
 * The cache round-trips DTOs via JSON + spatie/laravel-data hydration.
 * PHP's native object-deserialisation path is intentionally not used here:
 * even with `allowed_classes` it drives DTO constructors with arbitrary
 * cached strings, and the `JSON_THROW_ON_ERROR` flag makes any corruption
 * or schema skew loud rather than silently dropping rows.
 *
 * `Clock` is injected so the TTL window is deterministic in tests.
 *
 * Knowingly-accepted transient plaintext staging: the canonical
 * (`CanonicalTransaction::toArray()`) and enrichments payloads written here
 * carry sensitive content in the clear — `counterparty_name`/`description`/
 * `counterparty_iban`, and the decrypted `conflictingFields['stored']` value.
 * With `CACHE_STORE=file` that is a plaintext file under
 * `storage/framework/cache/`; the default `database` store is an on-disk SQLite
 * `cache` table. Either way sensitive content sits in cleartext at rest for the
 * 30-minute TTL between preview and confirm. This is a REVIEWED, accepted
 * exposure given the bounded TTL and transient staging role — the preview/confirm
 * flow runs only behind the unlocked app-lock. A future hardening encrypts these
 * payloads via `SensitiveColumnCodec` before `cache->put`, which requires
 * threading the user id + session through this cache's put/get API and every
 * caller; deferred rather than done here. Not added to SensitiveFieldRegistry
 * (that enumerates DB columns, not cache keys) — tracked at the call site.
 */
final class PreviewCache
{
    public function __construct(
        private readonly Repository $cache,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<CanonicalTransaction>  $canonical
     * @param  list<PendingEnrichment>  $enrichments
     */
    public function put(int $importRunId, ImportPreviewResult $result, array $canonical, array $enrichments = []): void
    {
        $ttl = $this->clock->now()->addMinutes(30);

        $this->cache->put(
            $this->previewKey($importRunId),
            $result->toArray(),
            $ttl,
        );

        $canonicalPayload = json_encode(
            array_map(static fn (CanonicalTransaction $c): array => $c->toArray(), $canonical),
            JSON_THROW_ON_ERROR,
        );

        $this->cache->put(
            $this->canonicalKey($importRunId),
            $canonicalPayload,
            $ttl,
        );

        $enrichmentsPayload = json_encode(
            array_map(static fn (PendingEnrichment $p): array => $p->toArray(), $enrichments),
            JSON_THROW_ON_ERROR,
        );

        $this->cache->put(
            $this->enrichmentsKey($importRunId),
            $enrichmentsPayload,
            $ttl,
        );
    }

    /**
     * Returns the cached preview result for an import run, or `null` when
     * the cache key is absent / expired. A present-but-malformed payload
     * throws `PreviewCacheCorruptedException` so the wizard can tell the
     * difference between "your preview window elapsed" (re-upload) and
     * "the cache backend lost the shape" (still re-upload, but the cause
     * is a backend regression worth logging).
     */
    public function getPreview(int $importRunId): ?ImportPreviewResult
    {
        $key = $this->previewKey($importRunId);
        if (! $this->cache->has($key)) {
            return null;
        }

        $raw = $this->cache->get($key);
        if (! is_array($raw)) {
            throw new PreviewCacheCorruptedException($importRunId, $key);
        }

        return ImportPreviewResult::from($raw);
    }

    /**
     * Returns the cached canonical batch for an import run, or `null` when
     * the cache entry is absent / expired. Callers must distinguish
     * "missing" (null) from "empty list" — the latter is a legitimate
     * preview whose every row was a duplicate; the former means the
     * confirm step has no batch to replay and must surface a re-upload
     * prompt rather than confirming nothing.
     *
     * A present-but-malformed payload (non-string value, or a value that
     * fails JSON decode) throws `PreviewCacheCorruptedException` so the
     * cause is visible to the wizard and the logs.
     *
     * @return list<CanonicalTransaction>|null
     */
    public function getCanonical(int $importRunId): ?array
    {
        $key = $this->canonicalKey($importRunId);
        if (! $this->cache->has($key)) {
            return null;
        }

        $raw = $this->cache->get($key);
        if (! is_string($raw)) {
            throw new PreviewCacheCorruptedException($importRunId, $key);
        }

        try {
            /** @var array<int, array<string, mixed>> $list */
            $list = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PreviewCacheCorruptedException($importRunId, $key, $e);
        }

        return array_values(array_map(
            static fn (array $row): CanonicalTransaction => CanonicalTransaction::from($row),
            $list,
        ));
    }

    /**
     * Returns the cached pending-enrichments list for an import run, or
     * `null` when the cache entry is absent / expired. An empty list is
     * a legitimate value (a preview with no cross-format enrichments).
     *
     * A present-but-malformed payload throws
     * `PreviewCacheCorruptedException` for the same reason as
     * `getCanonical()`.
     *
     * @return list<PendingEnrichment>|null
     */
    public function getEnrichments(int $importRunId): ?array
    {
        $key = $this->enrichmentsKey($importRunId);
        if (! $this->cache->has($key)) {
            return null;
        }

        $raw = $this->cache->get($key);
        if (! is_string($raw)) {
            throw new PreviewCacheCorruptedException($importRunId, $key);
        }

        try {
            /** @var array<int, array<string, mixed>> $list */
            $list = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PreviewCacheCorruptedException($importRunId, $key, $e);
        }

        return array_values(array_map(
            static fn (array $row): PendingEnrichment => PendingEnrichment::from($row),
            $list,
        ));
    }

    public function forget(int $importRunId): void
    {
        $this->cache->forget($this->previewKey($importRunId));
        $this->cache->forget($this->canonicalKey($importRunId));
        $this->cache->forget($this->enrichmentsKey($importRunId));
    }

    /**
     * Mutates the cached preview's row at `$rowIndex` so its
     * `aliasFriendlyName` reflects a just-saved merchant alias. The
     * cache stays the source of truth — the next render reads the
     * updated row without re-running the full import pipeline.
     *
     * Returns true when the row was updated, false when the cache is
     * missing for the run OR the rowIndex falls outside the row list.
     * Both miss cases are silent: a stale dispatch from a previous
     * render or a tampered rowIndex never throws.
     *
     * The 30-minute TTL window is preserved on the rewrite: the
     * underlying cache key carries the same expiry the original
     * `put()` set, but the cache backend resets the TTL when the key
     * is overwritten — keeps the preview alive while the user is
     * actively renaming.
     */
    public function applyAliasInPlace(int $importRunId, int $rowIndex, string $friendlyName): bool
    {
        $preview = $this->getPreview($importRunId);
        if ($preview === null) {
            return false;
        }
        if (! array_key_exists($rowIndex, $preview->rows)) {
            return false;
        }

        $existing = $preview->rows[$rowIndex];
        $updated = new PreviewRowDto(
            rowIndex: $existing->rowIndex,
            status: $existing->status,
            accountId: $existing->accountId,
            bookedAt: $existing->bookedAt,
            counterpartyName: $existing->counterpartyName,
            counterpartyIban: $existing->counterpartyIban,
            description: $existing->description,
            categoryName: $existing->categoryName,
            amountMinor: $existing->amountMinor,
            currency: $existing->currency,
            error: $existing->error,
            diff: $existing->diff,
            paymentType: $existing->paymentType,
            aliasFriendlyName: $friendlyName,
        );

        $newRows = $preview->rows;
        $newRows[$rowIndex] = $updated;

        $rewritten = new ImportPreviewResult(
            importRunId: $preview->importRunId,
            rows: $newRows,
            accountsToName: $preview->accountsToName,
            enrichedCount: $preview->enrichedCount,
        );

        $ttl = $this->clock->now()->addMinutes(30);
        $this->cache->put($this->previewKey($importRunId), $rewritten->toArray(), $ttl);

        return true;
    }

    private function previewKey(int $importRunId): string
    {
        return sprintf('import.%d.preview', $importRunId);
    }

    private function canonicalKey(int $importRunId): string
    {
        return sprintf('import.%d.canonical', $importRunId);
    }

    private function enrichmentsKey(int $importRunId): string
    {
        return sprintf('import.%d.enrichments', $importRunId);
    }
}
