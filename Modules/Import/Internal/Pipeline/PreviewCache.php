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
 * @link ../../../../.docs/features/import/architecture.md#preview-cache
 */
final class PreviewCache
{
    private const int TTL_MINUTES = 30;

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
        $ttl = $this->clock->now()->addMinutes(self::TTL_MINUTES);

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

    // Malformed-but-present payload throws PreviewCacheCorruptedException
    // so the wizard can distinguish "preview window elapsed" from "the
    // cache backend lost the shape" (a regression worth logging).
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

    // Callers must distinguish "missing" (null — confirm has nothing to
    // replay, surface a re-upload prompt) from "empty list" (legitimate:
    // every row was a duplicate).
    /**
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

    // Returns false (never throws) when the cache is missing for the
    // run or rowIndex is out of range — a stale dispatch or tampered
    // index is silent. The TTL resets to a fresh 30 minutes on rewrite.
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

        $ttl = $this->clock->now()->addMinutes(self::TTL_MINUTES);
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
